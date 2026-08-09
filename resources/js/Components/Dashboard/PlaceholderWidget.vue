<script setup>
// Reserves the layout slot for a widget that isn't built yet
// (College Scheduling Progress, Faculty Workload Summary, Room
// Utilization, Academic Calendar, Recent Activity, AI Insights) so
// adding the real widget later is a drop-in swap, not a page
// redesign.
defineProps({
    title: { type: String, required: true },
    description: { type: String, required: true },
    icon: { type: String, required: true },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-6 text-center">
        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <i :class="['pi', icon]"></i>
        </span>
        <p class="text-sm font-semibold text-slate-500">{{ title }}</p>
        <p class="text-xs text-slate-400">{{ description }}</p>
        <span class="mt-1 rounded-full bg-slate-200 px-2.5 py-0.5 text-[10px] font-semibold tracking-wide text-slate-500 uppercase">Coming soon</span>
    </div>
</template>