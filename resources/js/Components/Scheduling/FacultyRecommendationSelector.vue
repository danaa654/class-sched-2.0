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
 */
import { ref, computed, watch, onMounted } from 'vue';
import AutoComplete from 'primevue/autocomplete';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';
import Button from 'primevue/button';

const props = defineProps({
    sectionId: { type: [Number, String], required: true },
    sectionSubjectId: { type: [Number, String], required: true },
    modelValue: { type: Object, required: true }, // current faculty meta (id, name, score, confidence, reasons, tier, badge, ...)
    // Controlled by the parent (Show.vue) — one "Show details" toggle
    // per subject drives Faculty/Room/Time together instead of each
    // selector having its own independent toggle.
    showDetails: { type: Boolean, default: false },
});

const emit = defineEmits(['updated']);

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const optionsUrl = () => route('scheduling.section-subjects.faculty-options', [props.sectionId, props.sectionSubjectId]);
const overrideUrl = () => route('scheduling.section-subjects.faculty-override', [props.sectionId, props.sectionSubjectId]);

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

/** Loads the recommended pool (no search text) — called on mount and whenever the search box is cleared. */
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

onMounted(loadRecommended);

/** Global search across ALL faculty, regardless of College — the recommended pool is still shown first. */
const search = async (event) => {
    const text = event.query?.trim() ?? '';
    loadingOptions.value = true;
    try {
        const url = new URL(optionsUrl(), window.location.origin);
        if (text.length > 0) url.searchParams.set('search', text);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();

        recommendedIds.value = new Set((data.recommended ?? []).map((f) => f.id));

        const recommended = (data.recommended ?? [])
            .filter((f) => text.length === 0 || f.name.toLowerCase().includes(text.toLowerCase()))
            .map((f) => ({ ...f, badge: recommendedBadge(f), recommended: true }));

        const others = (data.search_results ?? []).map((f) => ({ ...f, recommended: false }));

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
            body: JSON.stringify({ faculty_id: picked.id }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply this faculty selection.');

        current.value = data.faculty;
        query.value = data.faculty.name;

        emit('updated', { faculty: data.faculty, overall_score: data.overall_score });
    } catch (e) {
        // Revert the visible text back to the last confirmed faculty on failure.
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
            <span class="text-xs font-medium text-slate-500 uppercase">Faculty</span>
            <span class="text-xs font-semibold" :style="{ color: scoreColor(current.score ?? 0) }">
                {{ current.score ?? 0 }}%
            </span>
        </div>

        <AutoComplete
            v-model="query"
            :suggestions="suggestions"
            :loading="loadingOptions || applying"
            optionLabel="name"
            placeholder="Search faculty…"
            class="w-full faculty-recommendation-selector"
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
                        <span class="text-xs text-slate-500">{{ option.college ?? 'General Education' }}</span>
                        <span class="text-xs text-slate-400">·</span>
                        <span class="text-xs text-slate-500">{{ option.employment_type }}</span>
                        <span class="text-xs text-slate-400">·</span>
                        <span class="text-xs text-slate-500">Load: {{ option.current_load }}/{{ option.max_teaching_units }}</span>
                    </div>
                </div>
            </template>
            <template #empty>
                <span class="text-xs text-slate-500 px-2">No faculty found.</span>
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
.faculty-recommendation-selector :deep(.p-autocomplete-input) {
    font-size: 0.85rem;
}
</style>