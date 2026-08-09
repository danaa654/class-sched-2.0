<script setup>
import { computed } from 'vue';
import Card from 'primevue/card';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    progress: {
        type: Object,
        required: true,
        // { overall_percent, sections_scheduled, sections_total,
        //   faculty_assigned, rooms_assigned, total_subjects }
    },
});

// Same 90/70 banding the spec calls for. Kept local to this widget
// since it's describing overall scheduling completion, not Faculty
// Workload status (which uses FacultyWorkloadService's own 85/100
// thresholds — a different question with different colors).
const barColorClass = computed(() => {
    const percent = props.progress.overall_percent;

    if (percent >= 90) return '!bg-emerald-500';
    if (percent >= 70) return '!bg-amber-500';

    return '!bg-red-500';
});

const textColorClass = computed(() => {
    const percent = props.progress.overall_percent;

    if (percent >= 90) return 'text-emerald-600';
    if (percent >= 70) return 'text-amber-600';

    return 'text-red-600';
});
</script>

<template>
    <Card class="!rounded-2xl border border-slate-100 shadow-sm">
        <template #title>
            <span class="text-lg font-bold text-[#1E293B]">Scheduling Progress</span>
        </template>
        <template #content>
            <div class="mb-1 flex items-end justify-between">
                <span class="text-sm text-slate-500">
                    {{ progress.total_subjects > 0 ? `${progress.overall_percent}% of subjects scheduled` : 'No subjects to schedule yet' }}
                </span>
                <span class="text-2xl font-bold" :class="textColorClass">{{ progress.overall_percent }}%</span>
            </div>

            <ProgressBar
                :value="progress.overall_percent"
                :showValue="false"
                class="mb-6 h-2.5"
                :pt="{ value: { class: barColorClass } }"
            />

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-100 p-4">
                    <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Sections Scheduled</p>
                    <p class="mt-1 text-xl font-bold text-[#1E293B]">
                        {{ progress.sections_scheduled }} <span class="text-sm font-medium text-slate-400">/ {{ progress.sections_total }}</span>
                    </p>
                </div>
                <div class="rounded-xl border border-slate-100 p-4">
                    <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Faculty Assigned</p>
                    <p class="mt-1 text-xl font-bold text-[#1E293B]">
                        {{ progress.faculty_assigned }} <span class="text-sm font-medium text-slate-400">/ {{ progress.total_subjects }}</span>
                    </p>
                </div>
                <div class="rounded-xl border border-slate-100 p-4">
                    <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Rooms Assigned</p>
                    <p class="mt-1 text-xl font-bold text-[#1E293B]">
                        {{ progress.rooms_assigned }} <span class="text-sm font-medium text-slate-400">/ {{ progress.total_subjects }}</span>
                    </p>
                </div>
            </div>
        </template>
    </Card>
</template>