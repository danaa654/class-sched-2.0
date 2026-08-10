<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useTheme } from '@/composables/useTheme';

defineProps({
    status: {
        type: String,
    },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};

const inputClass = computed(() =>
    isDark.value
        ? '!border-white/15 !bg-white/[0.06] !text-white placeholder:!text-slate-500'
        : '!border-slate-900/10 !bg-white/70 !text-[#1E293B] placeholder:!text-slate-400'
);
</script>

<template>
    <Head title="Forgot Password" />

    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-6 py-16 transition-colors duration-300"
        :class="isDark ? 'bg-[#080D1A] text-white' : 'bg-gradient-to-br from-[#EEF2FF] via-[#F8FAFC] to-[#E0E7FF] text-[#1E293B]'"
    >
        <!-- Ambient glow field -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                class="absolute -top-40 -left-32 h-[26rem] w-[26rem] rounded-full blur-[110px] motion-safe:animate-[pulse_9s_ease-in-out_infinite]"
                :class="isDark ? 'bg-[#2563EB]/30' : 'bg-[#2563EB]/20'"
            />
            <div
                class="absolute bottom-[-8rem] -right-32 h-[26rem] w-[26rem] rounded-full blur-[110px] motion-safe:animate-[pulse_11s_ease-in-out_infinite]"
                :class="isDark ? 'bg-[#E11D2E]/20' : 'bg-[#E11D2E]/10'"
            />
        </div>

        <div class="absolute right-6 top-6 z-20">
            <ThemeToggle />
        </div>

        <div class="relative z-10 w-full max-w-md">
            <div class="mb-8 flex flex-col items-center text-center">
                <img
                    src="/logo.png"
                    alt="CLASSLY"
                    class="mb-4 h-16 w-16 drop-shadow-[0_0_20px_rgba(37,99,235,0.55)]"
                />
                <h1 class="text-2xl font-extrabold tracking-tight">CLASSLY</h1>
                <p class="mt-1 text-sm font-medium" :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'">
                    Class Scheduling and Management System
                </p>
            </div>

            <div
                class="rounded-3xl border p-8 backdrop-blur-2xl transition-colors duration-300"
                :class="isDark
                    ? 'border-white/15 bg-white/[0.07] shadow-[0_8px_40px_rgba(0,0,0,0.5)]'
                    : 'border-white/60 bg-white/60 shadow-[0_8px_32px_rgba(30,41,59,0.12)]'"
            >
                <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Forgot your password?</h2>
                <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                    Enter your email and we'll send you a link to reset it.
                </p>

                <Message
                    v-if="status"
                    severity="success"
                    :closable="false"
                    class="mt-5"
                    :class="isDark ? '!border-emerald-400/30 !bg-emerald-400/10 !text-emerald-200' : '!border-emerald-600/20 !bg-emerald-50 !text-emerald-700'"
                >
                    {{ status }}
                </Message>

                <form class="mt-6 flex flex-col gap-5" @submit.prevent="submit">
                    <div class="flex flex-col gap-2">
                        <label for="email" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Email</label>
                        <InputText
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            autofocus
                            :class="['w-full', inputClass]"
                            :invalid="!!form.errors.email"
                            placeholder="you@classly.local"
                        />
                        <small v-if="form.errors.email" class="text-red-400">
                            {{ form.errors.email }}
                        </small>
                    </div>

                    <Button
                        type="submit"
                        label="Email Password Reset Link"
                        class="!w-full !border-[#2563EB]/60 !bg-[#2563EB] shadow-[0_8px_24px_rgba(37,99,235,0.45)]"
                        size="large"
                        :loading="form.processing"
                    />

                    <Link
                        :href="route('login')"
                        class="text-center text-sm transition-colors"
                        :class="isDark ? 'text-slate-400 hover:text-[#5B9CFF]' : 'text-slate-500 hover:text-[#2563EB]'"
                    >
                        Back to login
                    </Link>
                </form>
            </div>
        </div>
    </div>
</template>