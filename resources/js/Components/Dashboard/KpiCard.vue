<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// A single KPI summary card (Active Sections, Faculty Members, Rooms,
// Scheduled Subjects, ...). Kept generic/reusable so future widgets
// (College Progress, Faculty Workload, Room Utilization) can drop in
// more of these without a new component each time.
//
// Neumorphic (soft UI) styling: the card is "extruded" from the same
// background color as the page (see .neu-card in app.css), with a
// pressed-in icon well and a colored accent glow behind the icon.
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

// Accent (icon + glow) color per KPI type. Kept separate from the neu
// surface color so the icon well still reads as "pressed in" while the
// icon itself pops with the app's existing accent palette.
const colorClasses = {
    blue: {
        icon: 'text-blue-600',
        iconDark: 'text-blue-400',
        glow: 'rgba(37, 99, 235, 0.35)',
        glowDark: 'rgba(56, 189, 248, 0.35)',
    },
    emerald: {
        icon: 'text-emerald-600',
        iconDark: 'text-emerald-400',
        glow: 'rgba(16, 185, 129, 0.35)',
        glowDark: 'rgba(52, 211, 153, 0.35)',
    },
    amber: {
        icon: 'text-amber-600',
        iconDark: 'text-amber-400',
        glow: 'rgba(217, 119, 6, 0.35)',
        glowDark: 'rgba(251, 191, 36, 0.35)',
    },
    violet: {
        icon: 'text-violet-600',
        iconDark: 'text-violet-400',
        glow: 'rgba(124, 58, 237, 0.35)',
        glowDark: 'rgba(167, 139, 250, 0.35)',
    },
};

const classes = computed(() => colorClasses[props.color] ?? colorClasses.blue);
const isClickable = computed(() => !!props.href);
const glowStyle = computed(() => ({
    '--neu-glow-color': props.isDark ? classes.value.glowDark : classes.value.glow,
}));
</script>

<template>
    <component
        :is="isClickable ? Link : 'div'"
        :href="isClickable ? route(href) : undefined"
        class="neu-card flex items-center gap-4 rounded-2xl p-5 transition-colors duration-300"
        :class="isClickable ? 'neu-card--clickable cursor-pointer' : ''"
    >
        <span
            class="neu-icon-well neu-glow flex h-12 w-12 flex-none items-center justify-center rounded-xl"
            :style="glowStyle"
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