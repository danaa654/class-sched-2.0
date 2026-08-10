<script setup>
/**
 * Interactive Time Recommendation Selector.
 *
 * Replaces the old static "Time" block on the Auto Generate review
 * panel (Show.vue) with a click-to-edit popover — the Registrar can
 * change Days and Start/End Time without leaving the modal, the same
 * "review AND replace the AI pick instantly" flow
 * FacultyRecommendationSelector.vue / RoomRecommendationSelector.vue
 * already give for Faculty and Room.
 *
 * Backed by:
 *   POST scheduling.section-subjects.time-override  (apply pick, recompute score)
 *
 * A conflicting or off-pattern pick (e.g. 3 meetings/week for a
 * Subject that expects 2) is never rejected outright — it's applied
 * and flagged as a Manual Override, exactly like an out-of-pool
 * Faculty/Room pick, so the Registrar can see why and still decide.
 */
import { ref, computed, watch } from 'vue';
import Popover from 'primevue/popover';
import MultiSelect from 'primevue/multiselect';
import DatePicker from 'primevue/datepicker';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import ProgressBar from 'primevue/progressbar';
import RecommendedTimeModal from './RecommendedTimeModal.vue';

const props = defineProps({
    sectionId: { type: [Number, String], required: true },
    sectionSubjectId: { type: [Number, String], required: true },
    modelValue: { type: Object, required: true }, // current time meta (days, start_time, end_time, score, confidence, reasons, ...)
});

const emit = defineEmits(['updated']);

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const overrideUrl = () => route('scheduling.section-subjects.time-override', [props.sectionId, props.sectionSubjectId]);

const current = ref({ ...props.modelValue });

watch(
    () => props.modelValue,
    (val) => {
        current.value = { ...val };
    },
    { deep: true }
);

const dayOptions = [
    { label: 'Monday', value: 'Mon' },
    { label: 'Tuesday', value: 'Tue' },
    { label: 'Wednesday', value: 'Wed' },
    { label: 'Thursday', value: 'Thu' },
    { label: 'Friday', value: 'Fri' },
    { label: 'Saturday', value: 'Sat' },
];

const dayPresets = [
    { label: 'MW', value: ['Mon', 'Wed'] },
    { label: 'TTH', value: ['Tue', 'Thu'] },
    { label: 'WF', value: ['Wed', 'Fri'] },
    { label: 'FRI', value: ['Fri'] },
    { label: 'SAT', value: ['Sat'] },
];

const dayAbbreviations = { Mon: 'M', Tue: 'T', Wed: 'W', Thu: 'TH', Fri: 'F', Sat: 'SAT' };
const orderedDayTokens = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const formatDays = (selected) => {
    if (!selected || selected.length === 0) return '';
    return orderedDayTokens.filter((t) => selected.includes(t)).map((t) => dayAbbreviations[t]).join('');
};

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

const timeStringToDate = (value) => {
    if (!value) return null;
    const [hours, minutes] = value.split(':').map(Number);
    const date = new Date();
    date.setHours(hours, minutes, 0, 0);
    return date;
};

const dateToTimeString = (date) => {
    if (!date) return null;
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
};

// --- Auto-adjust duration when the number of meeting days changes ---------
// The Registrar edits a *per-meeting* time (e.g. 1:00-2:00 PM), but the
// underlying commitment is really a *weekly* duration (the Subject's total
// required weekly hours, split evenly across however many days are
// selected). When a day is added/removed via the MultiSelect chips, we
// redistribute that weekly total across the new day count instead of
// silently leaving a stale per-meeting time — or, worse, silently
// dropping an already-selected day to make room for the new one.
const minutesBetween = (start, end) => {
    if (!start || !end) return 0;
    return Math.round((end.getTime() - start.getTime()) / 60000);
};

const addMinutes = (date, minutes) => {
    const result = new Date(date.getTime());
    result.setMinutes(result.getMinutes() + minutes);
    return result;
};

// Weekly total (minutes) to redistribute across days. Seeded from the
// Subject's required_weekly_hours when the popover opens (the source of
// truth — see MeetingPatternService/RecommendationService), and
// re-baselined whenever the Registrar manually edits Start/End so future
// day-count changes redistribute from the value they just typed.
const totalWeeklyMinutes = ref(null);
const adjustingForDayChange = ref(false);

const LUNCH_START_MIN = 12 * 60; // 12:00 PM
const LUNCH_END_MIN = 13 * 60; // 1:00 PM

// Hard ceiling on manually-picked meetings/week — mirrors the backend's
// `days` => max:3 validation on the time-override route. This only stops
// picking an unreasonable number of days; it never removes a day the
// Registrar already picked to make room for a new one.
const MAX_MEETINGS = 3;

const toMinutesOfDay = (date) => (date ? date.getHours() * 60 + date.getMinutes() : null);

const scoreColor = (score) => {
    if (score >= 85) return '#16a34a';
    if (score >= 65) return '#2563eb';
    return '#d97706';
};

const popover = ref(null);
const editDays = ref([]);
const editStart = ref(null);
const editEnd = ref(null);
const applying = ref(false);
const applyError = ref(null);

// How many meetings/week this Subject expects (from
// MeetingPatternService — hour-aware, e.g. a 4-hour/week Capstone
// subject expects 2). Captured when the popover opens; used only to
// label the hint text below, never to cap or truncate the selection.
const expectedMeetings = ref(2);

// Overlap check against the fixed lunch break (12:00 PM - 1:00 PM).
const lunchConflict = computed(() => {
    const startMin = toMinutesOfDay(editStart.value);
    const endMin = toMinutesOfDay(editEnd.value);
    if (startMin === null || endMin === null || endMin <= startMin) return false;
    return startMin < LUNCH_END_MIN && endMin > LUNCH_START_MIN;
});

// Adding/removing a day NEVER removes another already-selected day —
// each day picked becomes its own meeting occurrence, up to MAX_MEETINGS.
// Duration is redistributed evenly across whatever days remain so the
// subject's total weekly hours stay constant (e.g. 4 hrs/week over 2 days
// = 2 hrs/meeting; add a 3rd day and it becomes ~1h20m/meeting instead of
// silently dropping Friday).
watch(editDays, (newDays, oldDays) => {
    if (newDays.length > MAX_MEETINGS) {
        editDays.value = newDays.slice(0, MAX_MEETINGS);
        return; // watcher re-fires with the corrected (capped) value
    }

    const newCount = newDays?.length ?? 0;
    const oldCount = oldDays?.length ?? 0;
    if (newCount === oldCount || newCount === 0 || !editStart.value) return;
    if (!totalWeeklyMinutes.value) return;

    const perMeetingMinutes = Math.round(totalWeeklyMinutes.value / newCount);
    adjustingForDayChange.value = true;
    editEnd.value = addMinutes(editStart.value, perMeetingMinutes);
    adjustingForDayChange.value = false;
});

// If the Registrar manually retypes Start/End, that becomes the new
// per-meeting time — re-baseline the weekly total so future day-count
// changes redistribute from the value they just set.
watch([editStart, editEnd], ([start, end]) => {
    if (adjustingForDayChange.value) return;
    const perMeeting = minutesBetween(start, end);
    const count = editDays.value?.length || 1;
    totalWeeklyMinutes.value = perMeeting > 0 ? perMeeting * count : null;
});

const openEditor = (event) => {
    editDays.value = [...(current.value?.days ?? [])];
    editStart.value = timeStringToDate(current.value?.start_time);
    editEnd.value = timeStringToDate(current.value?.end_time);
    applyError.value = null;

    // Prefer the explicit expected_meetings the backend now sends;
    // fall back to the current day count so older cached results
    // (before this field existed) still behave sanely.
    expectedMeetings.value = current.value?.expected_meetings
        ?? current.value?.meetings_per_week
        ?? editDays.value.length
        ?? 2;

    // Baseline the weekly total to redistribute from. Prefer the
    // Subject's actual required_weekly_hours (source of truth) over the
    // recommendation's current per-meeting time x day count, since the
    // latter can be wrong if this row was previously mis-scheduled as a
    // single block (the exact bug this fixes).
    const requiredHours = current.value?.required_weekly_hours;
    if (requiredHours) {
        totalWeeklyMinutes.value = requiredHours * 60;
    } else {
        const baseMinutes = minutesBetween(editStart.value, editEnd.value);
        const baseCount = editDays.value.length || 1;
        totalWeeklyMinutes.value = baseMinutes > 0 ? baseMinutes * baseCount : null;
    }

    popover.value?.toggle(event);
};

const applyPreset = (preset) => {
    // Presets fully replace the selection (that's their whole point —
    // a one-click "use this combo instead"), capped at MAX_MEETINGS the
    // same way manual clicks are.
    editDays.value = preset.value.length > MAX_MEETINGS
        ? preset.value.slice(0, MAX_MEETINGS)
        : [...preset.value];
};

const canApply = computed(() => editDays.value.length > 0 && editStart.value && editEnd.value && editStart.value < editEnd.value);

const isManualOverride = computed(() => current.value?.manual_override === true);

// Non-blocking hint shown when the current picks don't match the
// Subject's expected meeting count — mirrors the "Manual Override"
// language used for Faculty/Room. Purely informational; Apply is never
// disabled for this.
const patternMismatch = computed(() => editDays.value.length > 0 && editDays.value.length !== expectedMeetings.value);

// Live weekly-hours total for whatever's currently in the editor, so the
// Registrar sees the running total update as they add/remove days or
// retype Start/End — matches the "Total: 4 hours/week ✓" example.
const liveWeeklyHours = computed(() => {
    const perMeeting = minutesBetween(editStart.value, editEnd.value);
    const count = editDays.value?.length || 0;
    if (perMeeting <= 0 || count === 0) return null;
    return Math.round(((perMeeting * count) / 60) * 100) / 100;
});

const requiredWeeklyHours = computed(() => current.value?.required_weekly_hours ?? null);

// --- Smart Day & Time Recommendation modal --------------------------------
// A conflict is never left as a dead end: whenever the editor detects the
// lunch break overlap (client-side, instant) OR the last Apply came back
// flagged as a Manual Override with a conflict reason (server-side —
// Faculty/Room/Section conflicts, which only ScheduleConflictService can
// actually know about), the Registrar gets a "Find Recommended Day & Time"
// action instead of being left to guess-and-check alternatives by hand.
const showRecommendModal = ref(false);

const activeConflictReason = computed(() => {
    if (lunchConflict.value) {
        return `${formatDays(editDays.value)} ${formatTimeRange(dateToTimeString(editStart.value), dateToTimeString(editEnd.value))} overlaps the 12:00 PM - 1:00 PM lunch break.`;
    }
    if (isManualOverride.value && current.value?.override_reason) {
        return current.value.override_reason;
    }
    return null;
});

const hasConflict = computed(() => activeConflictReason.value !== null);

// Passed to the recommendation modal: keep single-day alternatives
// the same length as whatever's currently in the editor. (We
// deliberately do NOT exclude the currently-picked day(s) here —
// same-day alternatives, e.g. other times on the Saturday the
// Registrar already picked, are exactly what the modal should
// surface first via the preferred-day grouping.)
const recommendSessionMinutes = computed(() => {
    const minutes = minutesBetween(editStart.value, editEnd.value);
    return minutes > 0 ? minutes : null;
});

const openRecommendations = () => {
    showRecommendModal.value = true;
};

/** Populates the editor with the Registrar's picked recommendation; Apply is still a separate, explicit click (spec item 7). */
const applyRecommendation = ({ days, start_time, end_time }) => {
    editDays.value = [...days];
    editStart.value = timeStringToDate(start_time);
    editEnd.value = timeStringToDate(end_time);
    applyError.value = null;
};


/** Applies the Registrar's Days/Start/End pick immediately, no modal close required. */
const apply = async () => {
    if (!canApply.value) return;

    applying.value = true;
    applyError.value = null;
    try {
        const response = await fetch(overrideUrl(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                days: editDays.value,
                start_time: dateToTimeString(editStart.value),
                end_time: dateToTimeString(editEnd.value),
            }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply this time.');

        current.value = data.time;
        emit('updated', { time: data.time, overall_score: data.overall_score });
        popover.value?.hide();
    } catch (e) {
        applyError.value = e.message ?? 'Could not apply this time.';
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
            <span class="text-xs font-medium text-slate-500 uppercase">Time</span>
            <span class="text-xs font-semibold" :style="{ color: scoreColor(current.score ?? 0) }">
                {{ current.score ?? 0 }}%
            </span>
        </div>

        <!-- Click-to-edit trigger — each meeting occurrence shown on its own
             line when the subject meets more than once a week, matching the
             "FRI · 1:00 PM–3:00 PM / SAT · 1:00 PM–3:00 PM" review format. -->
        <button
            type="button"
            class="w-full text-left text-sm font-medium text-slate-800 mb-1 border border-slate-200 rounded-md px-2.5 py-1.5 hover:border-primary-400 hover:bg-slate-50 transition-colors time-recommendation-trigger"
            @click="openEditor"
        >
            <div v-if="(current.days ?? []).length > 1" class="space-y-0.5">
                <div v-for="day in current.days" :key="day" class="flex items-center justify-between gap-2">
                    <span>{{ (dayAbbreviations[day] ?? day).toUpperCase() }} · {{ formatTimeRange(current.start_time, current.end_time) }}</span>
                </div>
            </div>
            <div v-else class="flex items-center justify-between gap-2">
                <span>{{ formatDays(current.days) }} · {{ formatTimeRange(current.start_time, current.end_time) }}</span>
                <i class="pi pi-pencil text-slate-400 text-xs"></i>
            </div>
        </button>

        <Popover ref="popover" class="time-recommendation-popover">
            <div class="p-1 w-72">
                <p class="text-xs font-semibold text-slate-600 mb-2">Edit Day &amp; Time</p>

                <div class="flex flex-wrap gap-1 mb-2">
                    <Button
                        v-for="preset in dayPresets"
                        :key="preset.label"
                        :label="preset.label"
                        size="small"
                        text
                        class="!text-xs !py-1 !px-2"
                        @click="applyPreset(preset)"
                    />
                </div>

                <MultiSelect
                    v-model="editDays"
                    :options="dayOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Select days"
                    class="w-full mb-1"
                    display="chip"
                />
                <p class="text-[11px] text-slate-400 mb-1">
                    This subject expects {{ expectedMeetings }}x per week —
                    picking a day adds it as its own meeting; picking it again removes it.
                </p>
                <p v-if="patternMismatch" class="text-[11px] text-amber-600 mb-2 flex items-center gap-1">
                    <i class="pi pi-info-circle"></i>
                    {{ editDays.length }}x/week doesn't match this subject's usual {{ expectedMeetings }}x pattern — still applies as a Manual Override.
                </p>
                <p v-else class="mb-2"></p>

                <!-- Each selected day is its own meeting occurrence, all sharing the
                     Start/End below; the weekly total updates live as days are added
                     or removed so the Registrar can see the 4-hrs/week math directly. -->
                <div v-if="editDays.length > 1" class="mb-2 space-y-1">
                    <p class="text-xs font-medium text-slate-600">Meeting Schedule</p>
                    <div v-for="(day, idx) in editDays" :key="day" class="flex items-center justify-between text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                        <span>Meeting {{ idx + 1 }} — {{ dayOptions.find((o) => o.value === day)?.label ?? day }}</span>
                        <span v-if="editStart && editEnd">{{ formatTimeRange(dateToTimeString(editStart), dateToTimeString(editEnd)) }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-1">
                    <div>
                        <label class="text-xs text-slate-500 mb-1 block">Start</label>
                        <DatePicker v-model="editStart" timeOnly hourFormat="12" class="w-full" inputClass="w-full text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-500 mb-1 block">End</label>
                        <DatePicker v-model="editEnd" timeOnly hourFormat="12" class="w-full" inputClass="w-full text-sm" />
                    </div>
                </div>

                <p v-if="liveWeeklyHours !== null" class="text-[11px] mb-2" :class="requiredWeeklyHours && liveWeeklyHours !== requiredWeeklyHours ? 'text-amber-600' : 'text-slate-400'">
                    Total: {{ liveWeeklyHours }} {{ liveWeeklyHours === 1 ? 'hour' : 'hours' }}/week
                    <template v-if="requiredWeeklyHours">
                        (required {{ requiredWeeklyHours }})
                        <i v-if="liveWeeklyHours === requiredWeeklyHours" class="pi pi-check text-green-600"></i>
                        <i v-else class="pi pi-exclamation-triangle"></i>
                    </template>
                </p>

                <div v-if="hasConflict" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2 py-1.5 mb-2">
                    <p class="flex items-start gap-1 mb-1.5">
                        <i class="pi pi-exclamation-triangle mt-0.5"></i>
                        <span>{{ activeConflictReason }}</span>
                    </p>
                    <Button
                        label="Find Recommended Day & Time"
                        icon="pi pi-sparkles"
                        size="small"
                        class="!text-xs !py-1 w-full"
                        @click="openRecommendations"
                    />
                </div>

                <p v-if="applyError" class="text-xs text-red-600 mb-2">{{ applyError }}</p>

                <div class="flex justify-end gap-2">
                    <Button label="Cancel" size="small" severity="secondary" text @click="popover?.hide()" />
                    <Button label="Apply" size="small" :loading="applying" :disabled="!canApply" @click="apply" />
                </div>
            </div>
        </Popover>

        <RecommendedTimeModal
            v-model:visible="showRecommendModal"
            :section-id="sectionId"
            :section-subject-id="sectionSubjectId"
            :preferred-days="editDays"
            :session-minutes="recommendSessionMinutes"
            :conflict-reason="activeConflictReason"
            @select="applyRecommendation"
        />

        <!-- Manual Override badge/explanation — mirrors Faculty/Room selectors -->
        <div v-if="isManualOverride" class="flex items-center gap-1 mt-1.5">
            <Tag value="Manual Override" severity="warning" icon="pi pi-exclamation-triangle" class="!text-[0.6rem]" />
        </div>
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
    </div>
</template>

<style scoped>
.time-recommendation-trigger:hover {
    border-color: var(--p-primary-400, #60a5fa);
}
</style>