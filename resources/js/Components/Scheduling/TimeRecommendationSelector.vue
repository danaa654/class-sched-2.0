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

const openEditor = (event) => {
    editDays.value = [...(current.value?.days ?? [])];
    editStart.value = timeStringToDate(current.value?.start_time);
    editEnd.value = timeStringToDate(current.value?.end_time);
    applyError.value = null;
    popover.value?.toggle(event);
};

const applyPreset = (preset) => {
    editDays.value = [...preset.value];
};

const canApply = computed(() => editDays.value.length > 0 && editStart.value && editEnd.value && editStart.value < editEnd.value);

const isManualOverride = computed(() => current.value?.manual_override === true);

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

        <!-- Click-to-edit trigger -->
        <button
            type="button"
            class="w-full text-left text-sm font-medium text-slate-800 mb-1 border border-slate-200 rounded-md px-2.5 py-1.5 hover:border-primary-400 hover:bg-slate-50 transition-colors flex items-center justify-between gap-2 time-recommendation-trigger"
            @click="openEditor"
        >
            <span>{{ formatDays(current.days) }} · {{ formatTimeRange(current.start_time, current.end_time) }}</span>
            <i class="pi pi-pencil text-slate-400 text-xs"></i>
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
                    class="w-full mb-2"
                    display="chip"
                />

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="text-xs text-slate-500 mb-1 block">Start</label>
                        <DatePicker v-model="editStart" timeOnly hourFormat="12" class="w-full" inputClass="w-full text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-500 mb-1 block">End</label>
                        <DatePicker v-model="editEnd" timeOnly hourFormat="12" class="w-full" inputClass="w-full text-sm" />
                    </div>
                </div>

                <p v-if="applyError" class="text-xs text-red-600 mb-2">{{ applyError }}</p>

                <div class="flex justify-end gap-2">
                    <Button label="Cancel" size="small" severity="secondary" text @click="popover?.hide()" />
                    <Button label="Apply" size="small" :loading="applying" :disabled="!canApply" @click="apply" />
                </div>
            </div>
        </Popover>

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