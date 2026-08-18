<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import AmbientBackground from '@/Components/AmbientBackground.vue';
import AuthFlipCard from '@/Components/AuthFlipCard.vue';
import { useTheme } from '@/composables/useTheme';

defineProps({
    status: {
        type: String,
    },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');
</script>

<template>
    <Head title="Forgot Password" />

    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-6 py-16 transition-colors duration-300"
        :class="isDark ? 'bg-[#080D1A] text-white' : 'bg-gradient-to-br from-[#EEF2FF] via-[#F8FAFC] to-[#E0E7FF] text-[#1E293B]'"
    >
        <AmbientBackground :is-dark="isDark" />

        <div class="absolute right-6 top-6 z-20">
            <ThemeToggle />
        </div>

        <div class="relative z-10 w-full max-w-md">
            <AuthFlipCard initial-face="forgot-password" :is-dark="isDark" :forgot-status="status" />
        </div>
    </div>
</template>