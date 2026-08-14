<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import AmbientBackground from '@/Components/AmbientBackground.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const features = [
    {
        icon: 'pi pi-calendar',
        title: 'Scheduling',
        description: 'Build conflict-free class schedules in minutes, not spreadsheets.',
    },
    {
        icon: 'pi pi-users',
        title: 'Teachers',
        description: 'Assign instructors with real-time availability and load checking.',
    },
    {
        icon: 'pi pi-chart-bar',
        title: 'Reports',
        description: 'Generate printable, term-ready scheduling reports on demand.',
    },
];

// Decorative mini-calendar grid echoing the CLASSLY mark — one date is
// highlighted, the way the logo highlights a single date.
const calendarDots = Array.from({ length: 28 }, (_, i) => i);
const highlightedDot = 16;
</script>

<template>
    <Head title="Welcome" />

    <div
        class="relative min-h-screen overflow-hidden transition-colors duration-300"
        :class="isDark ? 'bg-[#080D1A] text-white' : 'bg-gradient-to-br from-[#EEF2FF] via-[#F8FAFC] to-[#E0E7FF] text-[#1E293B]'"
    >
        <AmbientBackground :is-dark="isDark" />

        <div class="relative z-10 flex min-h-screen flex-col">
            <!-- Glass navigation -->
            <header
                class="sticky top-0 z-30 border-b backdrop-blur-xl transition-colors duration-300"
                :class="isDark ? 'border-white/10 bg-white/[0.04]' : 'border-slate-900/5 bg-white/50'"
            >
                <nav class="mx-auto flex h-16 max-w-7xl items-center justify-between px-6 lg:px-8">
                    <div class="flex items-center gap-2.5">
                        <img src="/logo.png" alt="CLASSLY" class="h-8 w-8 drop-shadow-[0_0_12px_rgba(37,99,235,0.55)]" />
                        <span class="text-lg font-bold tracking-tight">CLASSLY</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <ThemeToggle />
                        <Link :href="route('login')">
                            <Button
                                label="Login"
                                :class="isDark
                                    ? '!border-white/20 !bg-white/10 !text-white hover:!bg-white/20'
                                    : '!border-slate-900/10 !bg-white/70 !text-[#1E293B] hover:!bg-white'"
                                class="backdrop-blur"
                            />
                        </Link>
                    </div>
                </nav>
            </header>

            <!-- Hero -->
            <section class="mx-auto w-full max-w-7xl px-6 py-16 lg:px-8 lg:py-24">
                <div class="grid items-center gap-12 lg:grid-cols-2">
                    <div>
                        <div
                            class="mb-6 inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-medium tracking-wide backdrop-blur"
                            :class="isDark ? 'border-white/15 bg-white/[0.06] text-slate-300' : 'border-slate-900/10 bg-white/60 text-slate-600'"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-[#2563EB]"></span>
                            Class Scheduling &amp; Management
                        </div>

                        <h1 class="text-5xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl lg:text-7xl">
                            CLASSLY
                        </h1>
                        <p class="mt-3 text-xl font-semibold sm:text-2xl" :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'">
                            Your Friendly Class Scheduler
                        </p>
                        <p class="mt-6 max-w-xl text-lg" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                            A centralized platform for managing class schedules, teachers, rooms, and academic
                            planning.
                        </p>

                        <div class="mt-8 flex flex-wrap gap-4">
                            <Link :href="route('login')">
                                <Button
                                    label="Login"
                                    size="large"
                                    class="!border-[#2563EB]/60 !bg-[#2563EB] !px-6 shadow-[0_8px_24px_rgba(37,99,235,0.45)]"
                                />
                            </Link>
                        </div>
                    </div>

                    <!-- Signature calendar mark: free-floating, no container -->
                    <div class="flex justify-center lg:justify-end">
                        <div class="flex flex-col items-center text-center">
                            <img
                                src="/logo.png"
                                alt="CLASSLY calendar mark"
                                class="mb-6 h-40 w-40 drop-shadow-[0_0_36px_rgba(37,99,235,0.55)] lg:h-48 lg:w-48"
                            />

                            <!-- Mini calendar grid, one date lit up like the logo -->
                            <div class="mb-6 grid w-40 grid-cols-7 gap-1.5">
                                <span
                                    v-for="dot in calendarDots"
                                    :key="dot"
                                    class="h-2 w-2 rounded-[3px]"
                                    :class="dot === highlightedDot
                                        ? 'bg-[#E11D2E] shadow-[0_0_8px_rgba(225,29,46,0.8)]'
                                        : isDark ? 'bg-white/15' : 'bg-slate-900/10'"
                                />
                            </div>

                            <p class="max-w-xs text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                                Academic scheduling made simple, organized, and conflict-free.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Feature cards -->
            <section class="mx-auto w-full max-w-7xl px-6 pb-16 lg:px-8 lg:pb-24">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="neon-frame group rounded-2xl p-[1.5px]"
                    >
                        <div
                            class="h-full rounded-[15px] border p-6 backdrop-blur-xl transition-all duration-300"
                            :class="isDark
                                ? 'border-white/10 bg-[#0B1220]/90 group-hover:bg-[#0B1220]/75'
                                : 'border-slate-900/5 bg-white/85 group-hover:bg-white/70'"
                        >
                            <div
                                class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl border transition-colors"
                                :class="isDark
                                    ? 'border-white/10 bg-[#2563EB]/20 group-hover:bg-[#2563EB]/30'
                                    : 'border-slate-900/5 bg-[#2563EB]/10 group-hover:bg-[#2563EB]/20'"
                            >
                                <i :class="feature.icon" class="text-2xl" :style="{ color: isDark ? '#5B9CFF' : '#2563EB' }"></i>
                            </div>
                            <h3 class="mb-1 text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ feature.title }}</h3>
                            <p class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ feature.description }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer
                class="mt-auto border-t backdrop-blur-xl transition-colors duration-300"
                :class="isDark ? 'border-white/10 bg-white/[0.03]' : 'border-slate-900/5 bg-white/40'"
            >
                <div class="mx-auto max-w-7xl px-6 py-6 text-center text-sm lg:px-8" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    CLASSLY &copy; 2026
                </div>
            </footer>
        </div>
    </div>
</template>