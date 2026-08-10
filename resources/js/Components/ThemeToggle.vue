<script setup>
import { computed } from 'vue';
import { useTheme } from '@/composables/useTheme';

const { theme, toggleTheme } = useTheme();
const isDark = computed(() => theme.value === 'dark');
</script>

<template>
    <button
        type="button"
        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
        class="flex h-10 w-10 items-center justify-center rounded-full border backdrop-blur transition-colors"
        :class="isDark
            ? 'border-white/20 bg-white/10 text-white hover:bg-white/20'
            : 'border-slate-900/10 bg-white/60 text-[#1E293B] hover:bg-white/80'"
        @click="toggleTheme"
    >
        <i v-if="isDark" class="pi pi-sun text-base"></i>
        <i v-else class="pi pi-moon text-base"></i>
    </button>
</template>