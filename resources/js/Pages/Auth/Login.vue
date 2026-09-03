<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ThemeToggle from '@/Components/ThemeToggle.vue';
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
    <Head title="Login" />

    <div class="min-h-screen" :class="isDark ? 'bg-[#020617]' : 'bg-[#BAE6FD]'">
        <div class="relative overflow-hidden" :class="isDark ? 'bg-[#0B1220]' : 'bg-[#F2F2F2]'">

            <!-- ============ Blue gradient hero, same treatment as the Welcome page ============ -->
            <div
                class="relative overflow-hidden bg-gradient-to-br"
                :class="isDark ? 'from-[#075985] via-[#0369A1] to-[#0284C7]' : 'from-[#7DD3FC] via-[#38BDF8] to-[#0284C7]'"
            >
                <!-- decorative blobs, matching the Welcome page hero -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden">
                    <div class="absolute -left-10 top-24 h-16 w-16 rounded-full border-4 border-white/30"></div>
                    <div class="absolute left-16 top-16 h-10 w-10 rounded-full bg-white/15"></div>
                    <div class="absolute right-[18%] top-10 h-8 w-8 rounded-full bg-white/20"></div>
                    <div class="absolute bottom-16 right-[8%] h-24 w-24 rounded-full border-4 border-white/25"></div>
                    <svg class="absolute right-6 top-16 h-24 w-24 text-white/25 lg:right-16" viewBox="0 0 60 60" fill="currentColor">
                        <circle v-for="n in 16" :key="n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                    </svg>
                </div>

                <div class="relative z-10 flex items-center justify-between px-6 pt-6 lg:px-10">
                    <Link :href="route('welcome')" class="flex items-center gap-2.5">
                        <img src="/logo.png" alt="CLASSLY" class="h-8 w-8 rounded-lg bg-white/90 p-1" />
                        <span class="text-lg font-bold tracking-tight text-white">CLASSLY</span>
                    </Link>
                    <ThemeToggle />
                </div>

                <div class="relative z-10 mx-auto max-w-md px-6 pb-0 pt-6 text-center">
                    <p class="text-sm font-semibold uppercase tracking-widest text-white/80">Welcome back</p>
                    <h1 class="mt-2 text-3xl font-extrabold text-white sm:text-4xl">Sign in to CLASSLY</h1>
                    <p class="mx-auto mt-3 max-w-sm text-white/85">
                        Build, review, and finalize your term's schedule from one dashboard.
                    </p>
                </div>

                <!-- Wave divider: powder blue → white/dark (same shape as the Welcome page) -->
                <svg class="relative z-10 -mb-1 block w-full" viewBox="0 0 1440 220" preserveAspectRatio="none" style="height: 100px;">
                    <path
                        :fill="isDark ? '#0B1220' : '#F2F2F2'"
                        d="M0,90 C160,20 320,150 480,110 C640,70 720,180 880,150 C1040,120 1120,40 1280,80 C1360,100 1400,130 1440,110 L1440,220 L0,220 Z"
                    />
                </svg>
            </div>

            <!-- ============ Decorative shapes scattered across the white area ============ -->
            <div class="pointer-events-none absolute inset-x-0 bottom-0 top-40 overflow-hidden">
                <div
                    class="absolute -left-16 top-20 h-40 w-40 rounded-full border-4"
                    :class="isDark ? 'border-sky-400/10' : 'border-sky-200'"
                ></div>
                <div
                    class="absolute left-[6%] top-[58%] h-6 w-6 rounded-full"
                    :class="isDark ? 'bg-sky-400/20' : 'bg-sky-300/60'"
                ></div>
                <div
                    class="absolute left-[14%] bottom-24 h-16 w-16 rotate-12 rounded-2xl border-4"
                    :class="isDark ? 'border-sky-400/10' : 'border-sky-200'"
                ></div>
                <div
                    class="absolute right-[8%] top-16 h-10 w-10 rounded-full"
                    :class="isDark ? 'bg-sky-400/15' : 'bg-sky-200/70'"
                ></div>
                <div
                    class="absolute -right-14 top-[45%] h-48 w-48 rounded-full border-4"
                    :class="isDark ? 'border-sky-400/10' : 'border-sky-100'"
                ></div>
                <div
                    class="absolute right-[12%] bottom-16 h-12 w-12 -rotate-12 rounded-xl"
                    :class="isDark ? 'bg-sky-400/10' : 'bg-sky-100'"
                ></div>
                <svg class="absolute left-[4%] bottom-[8%] h-16 w-16" :class="isDark ? 'text-sky-400/10' : 'text-sky-200'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 9" :key="n" :cx="(n % 3) * 16 + 4" :cy="Math.floor(n / 3) * 16 + 4" r="2" />
                </svg>
                <svg class="absolute right-[3%] top-[30%] h-14 w-14" :class="isDark ? 'text-sky-400/10' : 'text-sky-200'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 9" :key="n" :cx="(n % 3) * 16 + 4" :cy="Math.floor(n / 3) * 16 + 4" r="2" />
                </svg>
            </div>

            <!-- ============ Card, pulled up over the wave ============ -->
            <div class="relative z-10 mx-auto -mt-44 w-full max-w-md px-6 pb-20 sm:-mt-56">
                <AuthFlipCard initial-face="login" :is-dark="isDark" :login-status="status" />
            </div>
        </div>
    </div>
</template>