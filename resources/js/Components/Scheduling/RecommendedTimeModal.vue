<script setup>
/**
 * Smart Day & Time Recommendation modal.
 *
 * Opened from TimeRecommendationSelector's "Find Recommended Day &
 * Time" action whenever the Registrar's manually-picked Day/Time
 * fails validation (lunch break overlap, or a Faculty/Room/Section
 * conflict flagged after Apply). Fetches ranked candidates from
 * SectionSubjectController::timeRecommendations(), which itself calls
 * RecommendationService::recommendTimes() — the exact same
 * candidate-generation/scoring/conflict-checking engine Auto
 * Generate uses. Nothing here invents a slot the backend didn't
 * already validate and score.
 *
 * Backed by:
 *   GET scheduling.section-subjects.time-recommendations
 */
import { ref, computed, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    visible: { type: Boolean, default: false },
    sectionId: { type: [Number, String], required: true },
    sectionSubjectId: { type: [Number, String], required: true },
    // Days the Registrar had just picked when the conflict was hit —
    // used only to group/label results ("Saturday alternatives" vs
    // "Other recommended days"); never changes which candidates are
    // valid or how they're scored (that's entirely server-side).
    preferredDays: { type: Array, default: () => [] },
    // Human-readable text for the conflict that triggered this modal,
    // e.g. "Saturday 10:30 AM-1:30 PM overlaps the 12:00 PM-1:00 PM
    // lunch break." Shown at the top for context.
    conflictReason: { type: String, default: null },
    // Days already held by this row's OTHER meetings — passed through
    // untouched so the backend never re-offers one of them back as a
    // "new" single-day option (see recommendSingleDaySlots()).
    excludeDays: { type: Array, default: () => [] },
    // Duration (minutes) of the specific occurrence being replaced —
    // keeps single-day alternatives the same length instead of
    // silently changing it. Omit to fall back to the Subject's usual
    // per-meeting length.
    sessionMinutes: { type: Number, default: null },
});

const emit = defineEmits(['update:visible', 'select']);

const loading = ref(false);
const error = ref(null);
const recommendations = ref([]);
const noScheduleMessage = ref(null);

const dayLabels = { Mon: 'Monday', Tue: 'Tuesday', Wed: 'Wednesday', Thu: 'Thursday', Fri: 'Friday', Sat: 'Saturday', Sun: 'Sunday' };
const dayAbbreviations = { Mon: 'M', Tue: 'T', Wed: 'W', Thu: 'TH', Fri: 'F', Sat: 'SAT', Sun: 'SUN' };
const orderedDayTokens = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const formatDays = (days) => orderedDayTokens.filter((t) => (days ?? []).includes(t)).map((t) => dayAbbreviations[t]).join(' / ');

const formatTimeRange = (start, end) => {
    const fmt = (value) => {
        if (!value) return '';
        const [h, m] = value.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
    };
    return `${fmt(start)} - ${fmt(end)}`;
};

const preferredDaysLabel = computed(() => {
    if (!props.preferredDays?.length) return null;
    return props.preferredDays.map((d) => dayLabels[d] ?? d).join(' & ');
});

const preferredGroup = computed(() => recommendations.value.filter((r) => r.matches_preferred_day));
const otherGroup = computed(() => recommendations.value.filter((r) => !r.matches_preferred_day));

const bestScore = computed(() => recommendations.value.reduce((max, r) => Math.max(max, r.score ?? 0), 0));
const isBest = (rec) => recommendations.value.length > 0 && (rec.score ?? 0) === bestScore.value;

const scoreColor = (score) => {
    if (score >= 85) return '#16a34a';
    if (score >= 65) return '#2563eb';
    return '#d97706';
};

const fetchRecommendations = async () => {
    loading.value = true;
    error.value = null;
    noScheduleMessage.value = null;
    recommendations.value = [];

    try {
        const params = new URLSearchParams();
        if (props.preferredDays?.length) {
            params.set('preferred_days', props.preferredDays.join(','));
        }
        if (props.excludeDays?.length) {
            params.set('exclude_days', props.excludeDays.join(','));
        }
        if (props.sessionMinutes && props.sessionMinutes > 0) {
            params.set('session_minutes', String(props.sessionMinutes));
        }
        const url = `${route('scheduling.section-subjects.time-recommendations', [props.sectionId, props.sectionSubjectId])}?${params.toString()}`;

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not load recommendations.');

        recommendations.value = data.recommendations ?? [];
        if (recommendations.value.length === 0) {
            noScheduleMessage.value = data.message ?? 'No valid schedule was found for this subject.';
        }
    } catch (e) {
        error.value = e.message ?? 'Could not load recommendations.';
        // eslint-disable-next-line no-console
        console.error(e);
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.visible,
    (isVisible) => {
        if (isVisible) fetchRecommendations();
    }
);

const select = (rec) => {
    emit('select', { days: rec.days, start_time: rec.start_time, end_time: rec.end_time });
    emit('update:visible', false);
};

const close = () => emit('update:visible', false);
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Recommended Day & Time"
        :style="{ width: '30rem' }"
        class="recommended-time-modal"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <p v-if="conflictReason" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2.5 py-1.5 mb-3 flex items-start gap-1.5">
            <i class="pi pi-exclamation-triangle mt-0.5"></i>
            <span>{{ conflictReason }}</span>
        </p>

        <div v-if="loading" class="flex items-center justify-center py-8 text-slate-400 text-sm gap-2">
            <i class="pi pi-spin pi-spinner"></i> Searching for valid schedules&hellip;
        </div>

        <div v-else-if="error" class="text-sm text-red-600 py-4">{{ error }}</div>

        <div v-else-if="noScheduleMessage" class="py-2">
            <p class="text-sm font-medium text-slate-700 mb-1">No valid schedule found</p>
            <p class="text-xs text-slate-500 mb-4">{{ noScheduleMessage }}</p>
            <div class="flex justify-end gap-2">
                <Button label="Edit Manually" size="small" severity="secondary" text @click="close" />
                <Button label="Cancel" size="small" @click="close" />
            </div>
        </div>

        <div v-else>
            <p class="text-xs text-slate-500 mb-3">
                We found {{ recommendations.length }} valid scheduling option{{ recommendations.length === 1 ? '' : 's' }} for this meeting.
            </p>

            <div v-if="preferredGroup.length" class="mb-4">
                <p class="text-xs font-semibold text-slate-600 uppercase mb-2">{{ preferredDaysLabel }} alternatives</p>
                <div class="space-y-2">
                    <div
                        v-for="(rec, idx) in preferredGroup"
                        :key="`preferred-${idx}`"
                        class="border rounded-lg p-3"
                        :class="isBest(rec) ? 'border-green-300 bg-green-50/60' : 'border-slate-200'"
                    >
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div>
                                <span v-if="isBest(rec)" class="inline-flex items-center gap-1 text-[11px] font-semibold text-green-700 mb-0.5">
                                    <i class="pi pi-star-fill"></i> Best overall match
                                </span>
                                <span v-if="rec.single_day && rec.expected_meetings > 1" class="inline-flex items-center gap-1 text-[10px] font-medium text-blue-600 mb-0.5 ml-1">
                                    <i class="pi pi-info-circle"></i> Single meeting
                                </span>
                                <p class="text-sm font-semibold text-slate-800">{{ formatDays(rec.days) }} &middot; {{ formatTimeRange(rec.start_time, rec.end_time) }}</p>
                            </div>
                            <span class="text-xs font-semibold shrink-0" :style="{ color: scoreColor(rec.score) }">{{ rec.score }}% Match</span>
                        </div>
                        <ProgressBar :value="rec.score" :showValue="false" style="height: 4px" class="mb-2" :pt="{ value: { style: `background:${scoreColor(rec.score)}` } }" />
                        <ul class="space-y-0.5 mb-2">
                            <li
                                v-for="(reason, ridx) in rec.reasons ?? []"
                                :key="ridx"
                                class="text-[11px] flex items-center gap-1"
                                :class="reason.type === 'warning' ? 'text-amber-600' : (reason.met ? 'text-green-600' : 'text-slate-400')"
                            >
                                <i :class="reason.type === 'warning' ? 'pi pi-exclamation-triangle' : (reason.met ? 'pi pi-check' : 'pi pi-times')"></i>{{ reason.label }}
                            </li>
                        </ul>
                        <div class="flex justify-end">
                            <Button label="Select" size="small" @click="select(rec)" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="otherGroup.length">
                <p class="text-xs font-semibold text-slate-600 uppercase mb-2">
                    {{ preferredGroup.length ? 'Other recommended days' : 'Recommended options' }}
                </p>
                <div class="space-y-2">
                    <div
                        v-for="(rec, idx) in otherGroup"
                        :key="`other-${idx}`"
                        class="border rounded-lg p-3"
                        :class="isBest(rec) ? 'border-green-300 bg-green-50/60' : 'border-slate-200'"
                    >
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div>
                                <span v-if="isBest(rec)" class="inline-flex items-center gap-1 text-[11px] font-semibold text-green-700 mb-0.5">
                                    <i class="pi pi-star-fill"></i> Best overall match
                                </span>
                                <span v-if="rec.single_day && rec.expected_meetings > 1" class="inline-flex items-center gap-1 text-[10px] font-medium text-blue-600 mb-0.5 ml-1">
                                    <i class="pi pi-info-circle"></i> Single meeting
                                </span>
                                <p class="text-sm font-semibold text-slate-800">{{ formatDays(rec.days) }} &middot; {{ formatTimeRange(rec.start_time, rec.end_time) }}</p>
                            </div>
                            <span class="text-xs font-semibold shrink-0" :style="{ color: scoreColor(rec.score) }">{{ rec.score }}% Match</span>
                        </div>
                        <ProgressBar :value="rec.score" :showValue="false" style="height: 4px" class="mb-2" :pt="{ value: { style: `background:${scoreColor(rec.score)}` } }" />
                        <ul class="space-y-0.5 mb-2">
                            <li
                                v-for="(reason, ridx) in rec.reasons ?? []"
                                :key="ridx"
                                class="text-[11px] flex items-center gap-1"
                                :class="reason.type === 'warning' ? 'text-amber-600' : (reason.met ? 'text-green-600' : 'text-slate-400')"
                            >
                                <i :class="reason.type === 'warning' ? 'pi pi-exclamation-triangle' : (reason.met ? 'pi pi-check' : 'pi pi-times')"></i>{{ reason.label }}
                            </li>
                        </ul>
                        <div class="flex justify-end">
                            <Button label="Select" size="small" @click="select(rec)" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-3">
                <Button label="Cancel" size="small" severity="secondary" text @click="close" />
            </div>
        </div>
    </Dialog>
</template>