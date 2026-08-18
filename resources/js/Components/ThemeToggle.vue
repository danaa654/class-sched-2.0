<script setup>
import { computed } from 'vue';
import { useTheme } from '@/composables/useTheme';

const { theme, toggleTheme } = useTheme();
const isDark = computed(() => theme.value === 'dark');
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="isDark"
        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        class="neu-toggle relative inline-flex h-9 w-[68px] shrink-0 items-center rounded-full transition-colors duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
        :class="isDark ? 'neu-toggle--dark focus-visible:ring-teal-400' : 'neu-toggle--light focus-visible:ring-blue-400'"
        @click="toggleTheme"
    >
        <!-- track icons -->
        <i class="pi pi-moon neu-track-icon neu-track-icon--left" :class="isDark ? 'opacity-0' : 'opacity-60'"></i>
        <i class="pi pi-sun neu-track-icon neu-track-icon--right" :class="isDark ? 'opacity-70' : 'opacity-0'"></i>

        <!-- knob -->
        <span
            class="neu-knob absolute top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full transition-all duration-300 ease-out"
            :class="isDark ? 'neu-knob--dark left-[36px]' : 'neu-knob--light left-1'"
        >
            <i v-if="isDark" class="pi pi-moon text-[13px] text-slate-200"></i>
            <i v-else class="pi pi-sun text-[13px] text-amber-500"></i>
        </span>
    </button>
</template>

<style scoped>
/* Neumorphic track — sits on the navy header, so both states use the
   navy palette (matches neu-navy-raised) rather than a light/dark
   site-theme swap, and shadows are kept tight to avoid a glow. */
.neu-toggle {
    border: none;
    padding: 0;
}

.neu-toggle--light,
.neu-toggle--dark {
    background: #14225E;
    box-shadow:
        inset 2px 2px 5px rgba(0, 0, 0, 0.6),
        inset -2px -2px 5px rgba(255, 255, 255, 0.06);
}

/* Neumorphic knob (raised, soft) */
.neu-knob {
    box-shadow:
        2px 2px 5px rgba(0, 0, 0, 0.55),
        -1px -1px 4px rgba(255, 255, 255, 0.08);
}

.neu-knob--light {
    background: linear-gradient(145deg, #f4f6fa, #dfe4ec);
}

.neu-knob--dark {
    background: linear-gradient(145deg, #2b3348, #1a2030);
}

/* Small static icons sitting in the track, left = moon, right = sun */
.neu-track-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11px;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.neu-track-icon--left {
    left: 9px;
    color: #94a3b8;
}

.neu-track-icon--right {
    right: 9px;
    color: #facc15;
}
</style>