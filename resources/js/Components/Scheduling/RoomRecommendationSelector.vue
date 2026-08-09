<script setup>
/**
 * Interactive Room Recommendation Selector.
 *
 * Replaces the old static "Room" block on the Auto Generate review
 * panel (Show.vue) with a searchable dropdown the Registrar can use
 * to review AND replace the AI-selected room without leaving the
 * modal — the same "click to edit, search, or accept the AI pick"
 * flow FacultyRecommendationSelector.vue already gives for Faculty.
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
 */
import { ref, computed, watch, onMounted } from 'vue';
import AutoComplete from 'primevue/autocomplete';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    sectionId: { type: [Number, String], required: true },
    sectionSubjectId: { type: [Number, String], required: true },
    modelValue: { type: Object, required: true }, // current room meta (id, name, score, confidence, reasons, match_tier, badge, ...)
});

const emit = defineEmits(['updated']);

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const optionsUrl = () => route('scheduling.section-subjects.room-options', [props.sectionId, props.sectionSubjectId]);
const overrideUrl = () => route('scheduling.section-subjects.room-override', [props.sectionId, props.sectionSubjectId]);

const current = ref({ ...props.modelValue });
const query = ref(props.modelValue?.name ?? '');
const suggestions = ref([]);
const loadingOptions = ref(false);
const applying = ref(false);
const recommendedIds = ref(new Set());

watch(
    () => props.modelValue,
    (val) => {
        current.value = { ...val };
        query.value = val?.name ?? '';
    },
    { deep: true }
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

/** Loads the recommended pool (no search text) — called on mount and whenever the search box is cleared. */
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

onMounted(loadRecommended);

/** Global search across ALL active rooms, regardless of College/Type — the recommended pool is still shown first. */
const search = async (event) => {
    const text = event.query?.trim() ?? '';
    loadingOptions.value = true;
    try {
        const url = new URL(optionsUrl(), window.location.origin);
        if (text.length > 0) url.searchParams.set('search', text);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();

        recommendedIds.value = new Set((data.recommended ?? []).map((r) => r.id));

        const recommended = (data.recommended ?? [])
            .filter((r) => text.length === 0 || r.name.toLowerCase().includes(text.toLowerCase()))
            .map((r) => ({ ...r, badge: recommendedBadge(r), recommended: true }));

        const others = (data.search_results ?? []).map((r) => ({ ...r, recommended: false }));

        suggestions.value = [...recommended, ...others];
    } finally {
        loadingOptions.value = false;
    }
};

/** "Use This" — applies the Registrar's pick immediately, no modal close required. */
const select = async (event) => {
    const picked = event.value;
    if (!picked?.id || picked.id === current.value?.id) return;

    applying.value = true;
    try {
        const response = await fetch(overrideUrl(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ room_id: picked.id }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply this room selection.');

        current.value = data.room;
        query.value = data.room.name;

        emit('updated', { room: data.room, overall_score: data.overall_score });
    } catch (e) {
        // Revert the visible text back to the last confirmed room on failure.
        query.value = current.value?.name ?? '';
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

        <AutoComplete
            v-model="query"
            :suggestions="suggestions"
            :loading="loadingOptions || applying"
            optionLabel="name"
            placeholder="Search rooms, e.g. Room 108…"
            class="w-full room-recommendation-selector"
            inputClass="w-full text-sm"
            panelClass="!max-w-none"
            :delay="250"
            forceSelection
            dropdown
            @complete="search"
            @dropdown-click="loadRecommended"
            @item-select="select"
        >
            <template #option="{ option }">
                <div class="flex flex-col gap-0.5 py-1 w-full">
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
                    </div>
                </div>
            </template>
            <template #empty>
                <span class="text-xs text-slate-500 px-2">No rooms found.</span>
            </template>
        </AutoComplete>

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

        <ul class="mt-2 space-y-0.5">
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
    </div>
</template>

<style scoped>
.room-recommendation-selector :deep(.p-autocomplete-input) {
    font-size: 0.85rem;
}
</style>