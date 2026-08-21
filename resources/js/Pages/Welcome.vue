<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import AmbientBackground from '@/Components/AmbientBackground.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General. This is distinct from CLASSLY's own system
// branding (name/logo/tagline below), which never changes based on
// this. Falls back gracefully: no logo shown if none configured.
const page = usePage();
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

const features = [
    {
        icon: 'pi pi-calendar',
        title: 'Scheduling',
        description: 'Build conflict-free class schedules in minutes, not spreadsheets.',
        details: [
            'Auto-generate schedule drafts in seconds',
            'Instant conflict detection for rooms, faculty, and time slots',
            'Review and finalize a term schedule in one click',
        ],
    },
    {
        icon: 'pi pi-users',
        title: 'Teachers',
        description: 'Assign instructors with real-time availability and load checking.',
        details: [
            'See live faculty availability while assigning classes',
            'Automatic warnings before a teacher is overloaded',
            'Keep every faculty load fair and policy-compliant',
        ],
    },
    {
        icon: 'pi pi-chart-bar',
        title: 'Reports',
        description: 'Generate printable, term-ready scheduling reports on demand.',
        details: [
            'Export section, faculty, and room schedules as PDFs',
            'Track scheduling progress across the whole term',
            'Share print-ready reports with administrators instantly',
        ],
    },
];

// Which feature cards are currently showing their back (info) face.
const flipped = reactive({});
const toggleFlip = (title) => {
    flipped[title] = !flipped[title];
};

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
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2.5">
                            <img src="/logo.png" alt="CLASSLY" class="h-8 w-8 drop-shadow-[0_0_12px_rgba(37,99,235,0.55)]" />
                            <span class="text-lg font-bold tracking-tight">CLASSLY</span>
                        </div>

                        <!-- School branding (Settings → General) — kept separate from CLASSLY's own mark above -->
                        <template v-if="schoolBranding.name">
                            <span class="h-5 w-px" :class="isDark ? 'bg-white/15' : 'bg-slate-900/10'"></span>
                            <div class="flex items-center gap-2">
                                <img
                                    v-if="schoolBranding.logoUrl"
                                    :src="schoolBranding.logoUrl"
                                    alt=""
                                    class="h-6 w-6 rounded-full object-cover"
                                    @error="$event.target.style.display = 'none'"
                                />
                                <span class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                                    {{ schoolBranding.name }}
                                </span>
                            </div>
                        </template>
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
                        class="feature-flip-scene cursor-pointer select-none"
                        role="button"
                        tabindex="0"
                        :aria-pressed="!!flipped[feature.title]"
                        @click="toggleFlip(feature.title)"
                        @keydown.enter.prevent="toggleFlip(feature.title)"
                        @keydown.space.prevent="toggleFlip(feature.title)"
                    >
                        <div class="feature-flip-card" :class="{ 'is-flipped': flipped[feature.title] }">
                            <!-- Front face -->
                            <div class="feature-flip-face feature-flip-face--front">
                                <div class="neon-frame-static h-full rounded-2xl p-[1.5px]">
                                    <div
                                        class="flex h-full flex-col rounded-[15px] border p-6 backdrop-blur-xl transition-colors duration-300"
                                        :class="isDark ? 'border-white/10 bg-[#0B1220]/90' : 'border-slate-900/5 bg-white/85'"
                                    >
                                        <div
                                            class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl border"
                                            :class="isDark ? 'border-white/10 bg-[#2563EB]/20' : 'border-slate-900/5 bg-[#2563EB]/10'"
                                        >
                                            <i :class="feature.icon" class="text-2xl" :style="{ color: isDark ? '#5B9CFF' : '#2563EB' }"></i>
                                        </div>
                                        <h3 class="mb-1 text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ feature.title }}</h3>
                                        <p class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ feature.description }}</p>
                                        <span
                                            class="mt-auto flex items-center gap-1.5 pt-4 text-xs font-medium"
                                            :class="isDark ? 'text-slate-500' : 'text-slate-400'"
                                        >
                                            <i class="pi pi-info-circle"></i>
                                            Tap to learn more
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Back face -->
                            <div class="feature-flip-face feature-flip-face--back">
                                <div class="neon-frame-static h-full rounded-2xl p-[1.5px]">
                                    <div
                                        class="flex h-full flex-col rounded-[15px] border p-6 backdrop-blur-xl transition-colors duration-300"
                                        :class="isDark ? 'border-white/10 bg-[#0B1220]/90' : 'border-slate-900/5 bg-white/85'"
                                    >
                                        <h3 class="mb-3 text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ feature.title }}</h3>
                                        <ul class="flex flex-col gap-2">
                                            <li
                                                v-for="detail in feature.details"
                                                :key="detail"
                                                class="flex items-start gap-2 text-sm"
                                                :class="isDark ? 'text-slate-300' : 'text-slate-600'"
                                            >
                                                <i class="pi pi-check-circle mt-0.5 shrink-0" :style="{ color: isDark ? '#5B9CFF' : '#2563EB' }"></i>
                                                <span>{{ detail }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
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
                    <template v-if="schoolBranding.name">&copy; 2026 {{ schoolBranding.name }} — Powered by CLASSLY</template>
                    <template v-else>CLASSLY &copy; 2026 -DJS</template>
                </div>
            </footer>
        </div>
    </div>
</template>