<script setup>
/**
 * Interactive Faculty Recommendation Selector — Prompt 8.11.
 *
 * Replaces the old static "Faculty" block on the Auto Generate review
 * panel (Show.vue) with a searchable dropdown the Registrar can use
 * to review AND replace the AI-selected faculty without leaving the
 * modal. Backed by:
 *   GET  scheduling.section-subjects.faculty-options   (recommended list + search)
 *   POST scheduling.section-subjects.faculty-override  (apply pick, recompute score)
 *
 * Everything below happens instantly — no dialog close, no page
 * reload. The parent (Show.vue) only needs to render this component
 * per row and listen for @updated to keep autoSummary in sync.
 *
 * ── WHY THIS IS A PLAIN COMBOBOX, NOT PrimeVue's AutoComplete ──────
 * An earlier version used PrimeVue's AutoComplete with its built-in
 * `dropdown` overlay, and tried to stop that overlay from popping
 * open on its own (onMounted, a parent Dialog's focus-trap, and
 * forceSelection's internal validation search all independently
 * triggered it) by forcing the overlay's CSS to `display: none`
 * whenever a `panelOpen` flag was false. That flag was real, but the
 * fix was not: AutoComplete opens its overlay *reactively* the
 * instant its internal `suggestions` list goes from empty to
 * non-empty, completely independent of whatever `panelOpen` said —
 * the CSS override just papered over a component that still believed
 * it was "open" and could still race the paint. RoomRecommendationSelector.vue
 * hit the visible version of this bug first (several Room panels open
 * at once after Auto Generate, nobody clicking anything); this file
 * carried the exact same risk and gets the exact same fix here, kept
 * visually and architecturally identical to Room on purpose so the
 * two dropdowns behave and look the same to the Registrar.
 *
 * This replaces AutoComplete with a small, fully self-owned combobox
 * — an <input> plus an absolutely-positioned list directly below it
 * — built from two clearly separate pieces of state instead of one
 * fragile one:
 *
 *   - `suggestions` / `recommendedIds` — the CALCULATED
 *     recommendation data, populated by loadRecommended()/search().
 *     Auto Generate/Regenerate never call either function, and
 *     nothing about loading this data can make anything visible.
 *   - `isOpen` — the dropdown's own open/closed state. Set true in
 *     exactly one place (openDropdown(), wired to this input's
 *     @click), and set false in exactly three places: selecting an
 *     option, Escape, and a genuine click outside this component
 *     (onClickOutside(), the same document-click pattern already
 *     used by NotificationBell.vue). Nothing else — not a watcher on
 *     props.modelValue, not a mount hook, not `suggestions` changing
 *     — ever touches `isOpen`.
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    sectionId: { type: [Number, String], required: true },
    sectionSubjectId: { type: [Number, String], required: true },
    modelValue: { type: Object, required: true }, // current faculty meta (id, name, score, confidence, reasons, tier, badge, ...)
    // Controlled by the parent (Show.vue) — one "Show details" toggle
    // per subject drives Faculty/Room/Time together instead of each
    // selector having its own independent toggle.
    showDetails: { type: Boolean, default: false },
    // A cheap signature (e.g. "Sat|13:00-17:00") the parent recomputes
    // from result.time. This selector's own conflictError is a purely
    // local ref that only ever gets set/cleared by ITS OWN select()
    // calls — it has no way to know the Registrar just fixed the
    // conflict by editing Day/Time in the sibling TimeRecommendationSelector.
    // Watching this signature lets a stale "Faculty Conflict: ... on
    // Sat 13:00-17:00" message clear itself once the time actually
    // changes, instead of sitting there forever describing a slot
    // that's no longer selected.
    timeSignature: { type: [String, Number], default: null },
});

const emit = defineEmits(['updated']);

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const optionsUrl = () => route('scheduling.section-subjects.faculty-options', [props.sectionId, props.sectionSubjectId]);
const overrideUrl = () => route('scheduling.section-subjects.faculty-override', [props.sectionId, props.sectionSubjectId]);

const root = ref(null);
const current = ref({ ...props.modelValue });
const query = ref(props.modelValue?.name ?? '');
const suggestions = ref([]);
const loadingOptions = ref(false);
const applying = ref(false);
const recommendedIds = ref(new Set());
// Surfaces a 422 conflict (e.g. "Faculty Conflict: ... already teaches
// ... on Tue,Thu 10:30-12:00") to the Registrar right where they made
// the pick, instead of only logging it to the console while silently
// reverting the dropdown.
const conflictError = ref('');

// DROPDOWN VISIBILITY — see the file header. Set true ONLY inside
// openDropdown() (this input's @click), set false ONLY by select(),
// Escape, or a real outside click. Never by data loading, never by a
// prop/watch reacting to a freshly-generated modelValue.
const isOpen = ref(false);
let searchDebounceTimer = null;

watch(
    () => props.modelValue,
    (val) => {
        current.value = { ...val };
        query.value = val?.name ?? '';
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

const badgeSeverity = (badge) => {
    switch (badge) {
        case 'Qualified Faculty':
            return 'success';
        case 'College Match':
            return 'info';
        case 'General Education Match':
            return 'info';
        case 'Manual Override':
            return 'warning';
        default:
            return 'secondary';
    }
};

const currentBadge = computed(() => current.value?.badge
    ?? (current.value?.tier === 'teaching_qualification' ? 'Qualified Faculty'
        : current.value?.tier === 'college_match' ? 'College Match'
        : current.value?.tier === 'general_education_match' ? 'General Education Match'
        : current.value?.selected_by_college_match ? 'College Match'
        : 'Qualified Faculty'));

const isManualOverride = computed(() => current.value?.manual_override === true || current.value?.tier === 'manual_override');

/**
 * Loads the recommended pool (no search text) — called ONLY from
 * openDropdown() below (a real @click on this input) or when the
 * Registrar types (search()). Never on mount, never from a
 * prop/watch reacting to a freshly-generated modelValue, never from
 * focus alone.
 */
const loadRecommended = async () => {
    loadingOptions.value = true;
    try {
        const response = await fetch(optionsUrl(), { headers: { Accept: 'application/json' } });
        const data = await response.json();
        recommendedIds.value = new Set((data.recommended ?? []).map((f) => f.id));
        suggestions.value = (data.recommended ?? []).map((f) => ({ ...f, badge: recommendedBadge(f), recommended: true }));
    } finally {
        loadingOptions.value = false;
    }
};

const recommendedBadge = (f) => {
    if (f.tier === 'teaching_qualification') return 'Qualified Faculty';
    if (f.tier === 'college_match') return 'College Match';
    if (f.tier === 'general_education_match') return 'General Education Match';
    return f.selected_by_college_match ? 'College Match' : 'Qualified Faculty';
};

/** Global search across ALL faculty, regardless of College — the recommended pool is still shown first. */
const search = async (text) => {
    const trimmed = (text ?? '').trim();
    loadingOptions.value = true;
    try {
        const url = new URL(optionsUrl(), window.location.origin);
        if (trimmed.length > 0) url.searchParams.set('search', trimmed);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();

        recommendedIds.value = new Set((data.recommended ?? []).map((f) => f.id));

        const recommended = (data.recommended ?? [])
            .filter((f) => trimmed.length === 0 || f.name.toLowerCase().includes(trimmed.toLowerCase()))
            .map((f) => ({ ...f, badge: recommendedBadge(f), recommended: true }));

        const others = (data.search_results ?? []).map((f) => ({ ...f, recommended: false }));

        suggestions.value = [...recommended, ...others];
    } finally {
        loadingOptions.value = false;
    }
};

/**
 * Opens THIS row's Faculty dropdown. The only place in this file
 * allowed to set isOpen = true — wired to a plain @click on the input
 * below, nothing else (not @focus, so a parent Dialog's focus-trap
 * can never trigger this even if focusOnShow ever changes upstream).
 */
const openDropdown = () => {
    if (isOpen.value) return;
    isOpen.value = true;
    conflictError.value = '';
    loadRecommended();
};

const closeDropdown = () => {
    isOpen.value = false;
    query.value = current.value?.name ?? '';
};

const onType = () => {
    if (!isOpen.value) isOpen.value = true;
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => search(query.value), 250);
};

const onClickOutside = (event) => {
    if (isOpen.value && root.value && !root.value.contains(event.target)) {
        closeDropdown();
    }
};

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onUnmounted(() => {
    document.removeEventListener('mousedown', onClickOutside);
    clearTimeout(searchDebounceTimer);
});

/** "Use This" — applies the Registrar's pick immediately, closes the dropdown, no modal close required. */
const select = async (option) => {
    if (!option?.id || option.id === current.value?.id) {
        closeDropdown();
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
            body: JSON.stringify({ faculty_id: option.id }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply this faculty selection.');

        current.value = data.faculty;
        query.value = data.faculty.name;
        isOpen.value = false;

        emit('updated', { faculty: data.faculty, overall_score: data.overall_score });
    } catch (e) {
        query.value = current.value?.name ?? '';
        conflictError.value = e.message || 'Could not apply this faculty selection.';
        // eslint-disable-next-line no-console
        console.error(e);
    } finally {
        applying.value = false;
    }
};
</script>

<template>
    <div ref="root" class="relative">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs font-medium text-slate-500 uppercase">Faculty</span>
            <span class="text-xs font-semibold" :style="{ color: scoreColor(current.score ?? 0) }">
                {{ current.score ?? 0 }}%
            </span>
        </div>

        <div class="relative">
            <input
                v-model="query"
                v-uppercase
                type="text"
                placeholder="Search faculty…"
                class="w-full text-sm font-medium border rounded-md pl-2.5 pr-7 py-1.5 border-slate-200 text-slate-800 bg-white focus:outline-none focus:border-primary-400 faculty-recommendation-input"
                autocomplete="off"
                @click="openDropdown"
                @input="onType"
                @keydown.escape="closeDropdown"
            />
            <i
                class="pi pi-chevron-down text-slate-400 text-xs absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none"
                :class="{ 'pi-spin pi-spinner': loadingOptions || applying }"
            ></i>

            <div
                v-if="isOpen"
                class="absolute z-50 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-md shadow-lg max-h-72 overflow-y-auto faculty-recommendation-panel"
            >
                <div v-if="loadingOptions" class="text-xs text-slate-500 px-3 py-3 text-center">
                    <i class="pi pi-spin pi-spinner mr-1"></i>Loading faculty…
                </div>
                <div v-else-if="!suggestions.length" class="text-xs text-slate-500 px-3 py-3 text-center">
                    No faculty found.
                </div>
                <div
                    v-for="option in suggestions"
                    v-else
                    :key="option.id"
                    class="flex flex-col gap-0.5 py-1.5 px-3 w-full cursor-pointer hover:bg-slate-50"
                    :class="{ 'bg-slate-50': option.id === current.id }"
                    @mousedown.prevent="select(option)"
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
                        <span class="text-xs text-slate-500">{{ option.college ?? 'General Education' }}</span>
                        <span class="text-xs text-slate-400">·</span>
                        <span class="text-xs text-slate-500">{{ option.employment_type }}</span>
                        <span class="text-xs text-slate-400">·</span>
                        <span class="text-xs text-slate-500">Load: {{ option.current_load }}/{{ option.max_teaching_units }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Conflict error — set when the backend rejects this pick
             (e.g. a Faculty Conflict with another Section already
             using this Day/Time). The dropdown text is already
             reverted by select() above; this just tells the
             Registrar WHY their pick didn't apply, instead of it
             silently snapping back with no explanation. -->
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

        <ul v-if="showDetails" class="mt-1 space-y-0.5">
            <li
                v-for="(reason, idx) in current.reasons ?? []"
                :key="idx"
                class="text-xs flex items-center gap-1"
                :class="reason.met ? 'text-green-600' : 'text-slate-400'"
            >
                <i :class="reason.met ? 'pi pi-check' : 'pi pi-minus'"></i>{{ reason.label }}
            </li>
        </ul>
    </div>
</template>

<style scoped>
.faculty-recommendation-input {
    font-size: 0.85rem;
}
</style>