<script setup>
/**
 * Interactive Room Recommendation Selector.
 *
 * Replaces the old static "Room" block on the Auto Generate review
 * panel (Show.vue) with a click-to-edit dropdown the Registrar can use
 * to review AND replace the AI-selected room without leaving the
 * modal — the same "click to edit, search, or accept the AI pick"
 * flow FacultyRecommendationSelector.vue gives for Faculty.
 * Backed by:
 *   GET  scheduling.section-subjects.room-options   (recommended list + search)
 *   POST scheduling.section-subjects.room-override  (apply pick, recompute score)
 *
 * The recommended pool (Program Match / College Match / Shared Room,
 * already hard-filtered by Type/Capacity/Availability) is shown
 * first. Typing a room name or code (e.g. "Room 108") searches EVERY
 * Active room regardless of College or Type, each scored live so the
 * Registrar can see exactly why an out-of-pool room isn't ideal
 * before deciding to use it anyway — full manual override freedom,
 * same as Faculty.
 *
 * Everything below happens instantly — no dialog close, no page
 * reload. The parent (Show.vue) only needs to render this component
 * per row and listen for @updated to keep autoSummary in sync.
 *
 * ── WHY THIS IS A PLAIN <button> + <Popover>, NOT PrimeVue's
 *    AutoComplete dropdown ──────────────────────────────────────
 * The previous version used PrimeVue's AutoComplete with its built-in
 * `dropdown` overlay, and tried to stop that overlay from popping
 * open on its own (onMounted, a parent Dialog's focus-trap, and
 * forceSelection's internal validation search all independently
 * triggered it) by forcing the overlay's CSS to `display: none`
 * whenever a `panelOpen` flag was false. That flag was real, but the
 * fix was not: AutoComplete opens its overlay *reactively* the
 * instant its internal `suggestions` list goes from empty to
 * non-empty, completely independent of whatever `panelOpen` said —
 * the CSS override just papered over a component that still believed
 * it was "open" and could still race the paint (see the multiple
 * simultaneously-open Room panels reported after Auto Generate).
 *
 * Rather than continue plugging individual PrimeVue internals, this
 * uses the same pattern already proven for Time
 * (TimeRecommendationSelector.vue's `openEditor()` + `<Popover
 * ref="popover">`): a plain trigger button whose only job is to call
 * `popover.value.toggle(event)` — nothing else in this file is
 * capable of opening it — plus two clearly separate pieces of state:
 *
 *   - `suggestions` / `recommendedIds` — the CALCULATED
 *     recommendation data. Auto Generate/Regenerate never touch
 *     these directly, but nothing stops them from being warm ahead
 *     of a click either; they carry no visibility of their own.
 *   - the Popover's own open/closed state — controlled ONLY by an
 *     explicit user click on the trigger button (`openDropdown`) or
 *     a pick being made (`select` calls `.hide()`). Nothing else in
 *     this component — not a watcher, not a mount hook, not a prop
 *     change coming from a freshly-generated `modelValue` — ever
 *     calls `.toggle()`/`.show()`.
 *
 * Popover already closes itself on an outside click/Escape and mutually
 * excludes any other open Popover instance, which is what gives us
 * "opening one Room dropdown closes any other that was open" for free.
 */
import { ref, computed, watch, nextTick } from 'vue';
import Popover from 'primevue/popover';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    sectionId: { type: [Number, String], required: true },
    sectionSubjectId: { type: [Number, String], required: true },
    modelValue: { type: Object, required: true }, // current room meta (id, name, score, confidence, reasons, match_tier, badge, ...)
    // Controlled by the parent (Show.vue) — one "Show details" toggle
    // per subject drives Faculty/Room/Time together.
    showDetails: { type: Boolean, default: false },
    // A cheap signature (e.g. "Sat|13:00-17:00") the parent recomputes
    // from result.time. See FacultyRecommendationSelector.vue's prop
    // of the same name — same stale-conflict-message problem, same fix.
    timeSignature: { type: [String, Number], default: null },
});

const emit = defineEmits(['updated']);

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const optionsUrl = () => route('scheduling.section-subjects.room-options', [props.sectionId, props.sectionSubjectId]);
const overrideUrl = () => route('scheduling.section-subjects.room-override', [props.sectionId, props.sectionSubjectId]);

const current = ref({ ...props.modelValue });

// Recommendation DATA only — populated by loadRecommended()/search().
// This is intentionally the only thing Auto Generate/Regenerate could
// ever influence (they never call either function directly, but even
// if a future change made them "warm" this cache, it still wouldn't
// open anything — see popoverOpen below).
const suggestions = ref([]);
const recommendedIds = ref(new Set());
const loadingOptions = ref(false);
const applying = ref(false);
// Surfaces a 422 conflict (e.g. "Room Conflict: ... already booked
// for ... on Tue,Thu 10:30-12:00") right where the pick was made,
// instead of only logging it while silently reverting the dropdown.
const conflictError = ref('');

// Popover VISIBILITY — completely separate from the recommendation
// data above, and touched from exactly two places: openDropdown()
// (an explicit @click on the trigger button below) and select()
// (closing after a pick is made). Nothing else — not a watcher on
// props.modelValue, not onMounted, not a parent re-render — calls
// popover.value.toggle()/.show()/.hide(). See the file header for why
// this replaced the old AutoComplete + forced-CSS-hidden-overlay
// approach.
const popover = ref(null);
const searchText = ref('');
let searchDebounceTimer = null;

watch(
    () => props.modelValue,
    (val) => {
        current.value = { ...val };
    },
    { deep: true }
);

// Clear a stale conflict message once the Day/Time this row is
// scheduled for actually changes — see the timeSignature prop comment.
watch(
    () => props.timeSignature,
    () => {
        conflictError.value = '';
    }
);

const scoreColor = (score) => {
    if (score >= 85) return '#16a34a';
    if (score >= 65) return '#2563eb';
    return '#d97706';
};

const STATUS_DOT = {
    green: '#16a34a',
    blue: '#2563eb',
    yellow: '#d97706',
    red: '#dc2626',
};

const statusDotColor = (statusColor) => STATUS_DOT[statusColor] ?? STATUS_DOT.blue;

// Same "X/Y hrs" shape Faculty's "Load: 0/24" already shows — trims a
// trailing ".0" (e.g. "8" instead of "8.0") but keeps a real decimal
// (e.g. "8.5") since scheduled_hours/max_hours can be fractional.
const formatHours = (value) => {
    const num = Number(value ?? 0);
    return Number.isInteger(num) ? String(num) : num.toFixed(1);
};

const badgeSeverity = (badge) => {
    switch (badge) {
        case 'Recommended Room':
            return 'success';
        case 'Program Match':
            return 'success';
        case 'College Match':
            return 'info';
        case 'Shared Room':
            return 'info';
        case 'Available':
            return 'secondary';
        case 'Administrator Override':
            return 'warning';
        case 'Manual Override':
            return 'warning';
        default:
            return 'secondary';
    }
};

const recommendedBadge = (r) => r.badge ?? (
    r.recommendation_level === 'preferred' ? 'Recommended Room'
        : r.match_tier === 'program' ? 'Program Match'
        : r.match_tier === 'college' ? 'College Match'
        : r.match_tier === 'shared' ? 'Shared Room'
        : 'Available'
);

const currentBadge = computed(() => current.value?.badge ?? recommendedBadge(current.value ?? {}));

// True hard-constraint override only (Wrong Type/Capacity/Occupied).
// A cross-college pick alone is no longer a hard override — see
// is_manual_override for the "Administrator Override" (cross-scope
// but explicitly recommended) case, shown as its own badge instead.
const isManualOverride = computed(() => current.value?.manual_override === true);

/**
 * Loads the recommended pool (no search text). Called ONLY from
 * openDropdown() below, i.e. only once the Registrar has actually
 * clicked this row's Room trigger — never on mount, never from a
 * prop/watch reacting to a freshly-generated `modelValue`, and never
 * from focus. This function fills `suggestions` with data; it has no
 * way to affect whether the Popover is visible (that's popover.value
 * itself, toggled only in openDropdown()/select()).
 */
const loadRecommended = async () => {
    loadingOptions.value = true;
    try {
        const response = await fetch(optionsUrl(), { headers: { Accept: 'application/json' } });
        const data = await response.json();
        recommendedIds.value = new Set((data.recommended ?? []).map((r) => r.id));
        suggestions.value = (data.recommended ?? []).map((r) => ({ ...r, badge: recommendedBadge(r), recommended: true }));
    } finally {
        loadingOptions.value = false;
    }
};

/** Global search across ALL active rooms, regardless of College/Type — the recommended pool is still shown first. */
const search = async (text) => {
    const trimmed = (text ?? '').trim();
    loadingOptions.value = true;
    try {
        const url = new URL(optionsUrl(), window.location.origin);
        if (trimmed.length > 0) url.searchParams.set('search', trimmed);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();

        recommendedIds.value = new Set((data.recommended ?? []).map((r) => r.id));

        const recommended = (data.recommended ?? [])
            .filter((r) => trimmed.length === 0 || r.name.toLowerCase().includes(trimmed.toLowerCase()))
            .map((r) => ({ ...r, badge: recommendedBadge(r), recommended: true }));

        const others = (data.search_results ?? []).map((r) => ({ ...r, recommended: false }));

        suggestions.value = [...recommended, ...others];
    } finally {
        loadingOptions.value = false;
    }
};

// Debounced as-you-type search inside the open popover — mirrors the
// old AutoComplete's :delay="250", just driven from a plain input
// instead of PrimeVue's own @complete event.
watch(searchText, (text) => {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => search(text), 250);
});

/**
 * Opens THIS row's Room dropdown. The only place in this file allowed
 * to call popover.value.toggle()/.show() — wired to a plain @click on
 * the trigger button in the template below, nothing else. Loads the
 * recommended pool fresh every time it opens so the list can never go
 * stale, but that data fetch happens strictly AFTER the user's click,
 * not as a side effect of it being available.
 */
const openDropdown = async (event) => {
    conflictError.value = '';
    searchText.value = '';
    popover.value?.toggle(event);
    await loadRecommended();
};

/** "Use This" — applies the Registrar's pick immediately, closes the dropdown, no modal close required. */
const select = async (option) => {
    if (!option?.id || option.id === current.value?.id) {
        popover.value?.hide();
        return;
    }

    applying.value = true;
    conflictError.value = '';
    try {
        const response = await fetch(overrideUrl(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ room_id: option.id }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply this room selection.');

        current.value = data.room;
        popover.value?.hide();

        emit('updated', { room: data.room, overall_score: data.overall_score });
    } catch (e) {
        conflictError.value = e.message || 'Could not apply this room selection.';
        // eslint-disable-next-line no-console
        console.error(e);
    } finally {
        applying.value = false;
    }
};
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs font-medium text-slate-500 uppercase">Room</span>
            <span class="flex items-center gap-1.5">
                <span
                    class="inline-block w-2 h-2 rounded-full"
                    :style="{ background: statusDotColor(current.status_color) }"
                    :title="current.status_color === 'red' ? 'Conflict' : current.status_color === 'green' ? 'Recommended' : current.status_color === 'yellow' ? 'Soft preference issue' : 'Valid alternative'"
                ></span>
                <span class="text-xs font-semibold" :style="{ color: scoreColor(current.score ?? 0) }">
                    {{ current.score ?? 0 }}%
                </span>
            </span>
        </div>

        <!-- Click-to-edit trigger — the ONLY thing in this component that
             may open the dropdown below. Auto Generate/Regenerate never
             call this; it only ever runs from a real user @click. -->
        <button
            type="button"
            class="w-full flex items-center justify-between gap-2 text-left text-sm font-medium border rounded-md px-2.5 py-1.5 border-slate-200 text-slate-800 hover:border-primary-400 hover:bg-slate-50 transition-colors room-recommendation-trigger"
            @click="openDropdown"
        >
            <span class="truncate">{{ current.name || 'Select a room…' }}</span>
            <i class="pi pi-chevron-down text-slate-400 text-xs shrink-0"></i>
        </button>

        <Popover ref="popover" class="room-recommendation-popover">
            <div class="p-1 w-80">
                <p class="text-xs font-semibold text-slate-600 mb-2">Select Room</p>

                <InputText
                    v-model="searchText"
                    v-uppercase
                    placeholder="Search rooms, e.g. Room 108…"
                    class="w-full text-sm mb-2"
                />

                <div v-if="loadingOptions" class="text-xs text-slate-500 px-2 py-3 text-center">
                    <i class="pi pi-spin pi-spinner mr-1"></i>Loading rooms…
                </div>
                <div v-else-if="!suggestions.length" class="text-xs text-slate-500 px-2 py-3 text-center">
                    No rooms found.
                </div>
                <ul v-else class="max-h-72 overflow-y-auto space-y-0.5">
                    <li
                        v-for="option in suggestions"
                        :key="option.id"
                        class="flex flex-col gap-0.5 py-1.5 px-2 w-full rounded-md cursor-pointer hover:bg-slate-100"
                        :class="{ 'bg-slate-50': option.id === current.id }"
                        @click="select(option)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-slate-800">{{ option.name }}</span>
                            <span class="text-xs font-semibold" :style="{ color: scoreColor(option.score) }">{{ option.score }}%</span>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <Tag
                                :value="option.recommended ? recommendedBadge(option) : (option.badge ?? 'Manual Override')"
                                :severity="badgeSeverity(option.recommended ? recommendedBadge(option) : option.badge)"
                                class="!text-[0.6rem]"
                            />
                            <span class="text-xs text-slate-500">{{ option.college ?? 'All Colleges' }}</span>
                            <span class="text-xs text-slate-400">·</span>
                            <span class="text-xs text-slate-500">{{ option.room_category || option.room_type }}</span>
                            <span class="text-xs text-slate-400">·</span>
                            <span class="text-xs text-slate-500">Capacity {{ option.capacity }}</span>
                            <template v-if="option.max_hours">
                                <span class="text-xs text-slate-400">·</span>
                                <span class="text-xs text-slate-500">Load: {{ formatHours(option.scheduled_hours) }}/{{ formatHours(option.max_hours) }} hrs</span>
                            </template>
                        </div>
                    </li>
                </ul>
            </div>
        </Popover>

        <!-- Conflict error — set when the backend rejects this pick
             (e.g. a Room Conflict with another Section already using
             this Day/Time). Tells the Registrar WHY the pick snapped
             back instead of it just silently reverting. -->
        <p v-if="conflictError" class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-2 py-1 mt-1.5">
            <i class="pi pi-exclamation-circle mr-1"></i>{{ conflictError }}
        </p>

        <!-- Recommendation Badge -->
        <div class="flex items-center gap-1 mt-1.5">
            <Tag :value="currentBadge" :severity="badgeSeverity(currentBadge)" class="!text-[0.6rem]" />
            <Tag v-if="isManualOverride" value="Manual Override" severity="warning" icon="pi pi-exclamation-triangle" class="!text-[0.6rem]" />
        </div>

        <!-- Manual Override explanation -->
        <p v-if="isManualOverride && current.override_reason" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2 py-1 mt-1.5">
            {{ current.override_reason }}
        </p>

        <ProgressBar
            :value="current.score ?? 0"
            :showValue="false"
            style="height: 6px"
            class="mt-2"
            :pt="{ value: { style: `background:${scoreColor(current.score ?? 0)}` } }"
        />

        <template v-if="showDetails">
            <ul class="mt-1 space-y-0.5">
                <li
                    v-for="(reason, idx) in current.reasons ?? []"
                    :key="idx"
                    class="text-xs flex items-center gap-1"
                    :class="reason.type === 'warning' ? 'text-amber-600' : (reason.met ? 'text-green-600' : 'text-slate-400')"
                >
                    <i :class="reason.type === 'warning' ? 'pi pi-exclamation-triangle' : (reason.met ? 'pi pi-check' : 'pi pi-times')"></i>{{ reason.label }}
                </li>
            </ul>

            <!-- Why this room? -->
            <p v-if="current.explanation && !isManualOverride" class="text-xs text-slate-500 mt-2 leading-relaxed">
                <i class="pi pi-info-circle text-slate-400 mr-1"></i>{{ current.explanation }}
            </p>
        </template>
    </div>
</template>

<style scoped>
.room-recommendation-trigger {
    background: transparent;
}
</style>