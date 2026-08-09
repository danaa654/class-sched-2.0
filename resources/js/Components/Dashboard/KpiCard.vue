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
});

const colorClasses = {
    blue: { bg: 'bg-blue-50', icon: 'text-blue-600' },
    emerald: { bg: 'bg-emerald-50', icon: 'text-emerald-600' },
    amber: { bg: 'bg-amber-50', icon: 'text-amber-600' },
    violet: { bg: 'bg-violet-50', icon: 'text-violet-600' },
};

const classes = computed(() => colorClasses[props.color] ?? colorClasses.blue);
const isClickable = computed(() => !!props.href);
</script>

<template>
    <component
        :is="isClickable ? Link : 'div'"
        :href="isClickable ? route(href) : undefined"
        class="flex items-center gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition-shadow"
        :class="isClickable ? 'hover:shadow-md hover:border-slate-200 cursor-pointer' : ''"
    >
        <span
            class="flex h-12 w-12 flex-none items-center justify-center rounded-xl"
            :class="classes.bg"
        >
            <i :class="['pi', icon, 'text-xl', classes.icon]"></i>
        </span>
        <div class="min-w-0">
            <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ label }}</p>
            <p class="mt-0.5 text-2xl font-bold text-[#1E293B]">
                {{ value }}<span v-if="valueSuffix" class="text-base font-semibold text-slate-400"> {{ valueSuffix }}</span>
            </p>
        </div>
    </component>
</template>