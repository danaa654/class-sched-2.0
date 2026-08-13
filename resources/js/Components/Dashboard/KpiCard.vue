<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// A single KPI summary card (Active Sections, Faculty Members, Rooms,
// Scheduled Subjects, ...). Kept generic/reusable so future widgets
// (College Progress, Faculty Workload, Room Utilization) can drop in
// more of these without a new component each time.
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    // Optional "X / Y" style secondary value, e.g. Scheduled Subjects.
    valueSuffix: { type: String, default: '' },
    icon: { type: String, required: true },
    color: {
        type: String,
        default: 'blue',
        validator: (value) => ['blue', 'emerald', 'amber', 'violet'].includes(value),
    },
    // Route name to navigate to when the card is clicked. Omit for a
    // non-clickable card.
    href: { type: String, default: null },
    isDark: { type: Boolean, default: false },
});

const colorClasses = {
    blue: {
        bg: 'bg-blue-50',
        icon: 'text-blue-600',
        bgDark: 'bg-blue-500/15',
        iconDark: 'text-blue-400',
    },
    emerald: {
        bg: 'bg-emerald-50',
        icon: 'text-emerald-600',
        bgDark: 'bg-emerald-500/15',
        iconDark: 'text-emerald-400',
    },
    amber: {
        bg: 'bg-amber-50',
        icon: 'text-amber-600',
        bgDark: 'bg-amber-500/15',
        iconDark: 'text-amber-400',
    },
    violet: {
        bg: 'bg-violet-50',
        icon: 'text-violet-600',
        bgDark: 'bg-violet-500/15',
        iconDark: 'text-violet-400',
    },
};

const classes = computed(() => colorClasses[props.color] ?? colorClasses.blue);
const isClickable = computed(() => !!props.href);
</script>

<template>
    <component
        :is="isClickable ? Link : 'div'"
        :href="isClickable ? route(href) : undefined"
        class="flex items-center gap-4 rounded-2xl border p-5 shadow-sm transition-colors duration-300"
        :class="[
            isDark
                ? 'border-white/10 bg-white/[0.06] backdrop-blur-xl'
                : 'border-slate-100 bg-white',
            isClickable
                ? (isDark ? 'hover:bg-white/[0.09] hover:border-white/20 cursor-pointer' : 'hover:shadow-md hover:border-slate-200 cursor-pointer')
                : '',
        ]"
    >
        <span
            class="flex h-12 w-12 flex-none items-center justify-center rounded-xl"
            :class="isDark ? classes.bgDark : classes.bg"
        >
            <i :class="['pi', icon, 'text-xl', isDark ? classes.iconDark : classes.icon]"></i>
        </span>
        <div class="min-w-0">
            <p
                class="text-xs font-semibold tracking-wide uppercase"
                :class="isDark ? 'text-slate-400' : 'text-slate-400'"
            >
                {{ label }}
            </p>
            <p
                class="mt-0.5 text-2xl font-bold"
                :class="isDark ? 'text-white' : 'text-[#1E293B]'"
            >
                {{ value }}<span v-if="valueSuffix" class="text-base font-semibold" :class="isDark ? 'text-slate-500' : 'text-slate-400'"> {{ valueSuffix }}</span>
            </p>
        </div>
    </component>
</template>