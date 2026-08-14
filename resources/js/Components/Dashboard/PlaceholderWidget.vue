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
    isDark: { type: Boolean, default: false },
});
</script>

<template>
    <div class="neon-frame-static rounded-2xl p-[1.5px]">
        <div
            class="flex flex-col items-center justify-center gap-2 rounded-[15px] p-6 text-center transition-colors duration-300"
            :class="isDark ? 'bg-[#0B1220]/80' : 'bg-white/80'"
        >
            <span
                class="flex h-10 w-10 items-center justify-center rounded-full"
                :class="isDark ? 'bg-white/10 text-slate-400' : 'bg-slate-100 text-slate-400'"
            >
                <i :class="['pi', icon]"></i>
            </span>
            <p class="text-sm font-semibold" :class="isDark ? 'text-slate-300' : 'text-slate-500'">{{ title }}</p>
            <p class="text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ description }}</p>
            <span
                class="mt-1 rounded-full px-2.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                :class="isDark ? 'bg-white/10 text-slate-400' : 'bg-slate-200 text-slate-500'"
            >
                Coming soon
            </span>
        </div>
    </div>
</template>