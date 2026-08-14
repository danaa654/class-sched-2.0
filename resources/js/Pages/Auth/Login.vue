<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Checkbox from 'primevue/checkbox';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import AmbientBackground from '@/Components/AmbientBackground.vue';
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
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const inputClass = computed(() =>
    isDark.value
        ? '!border-white/15 !bg-white/[0.06] !text-white placeholder:!text-slate-500'
        : '!border-slate-900/10 !bg-white/70 !text-[#1E293B] placeholder:!text-slate-400'
);
</script>

<template>
    <Head title="Login" />

    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-6 py-16 transition-colors duration-300"
        :class="isDark ? 'bg-[#080D1A] text-white' : 'bg-gradient-to-br from-[#EEF2FF] via-[#F8FAFC] to-[#E0E7FF] text-[#1E293B]'"
    >
        <AmbientBackground :is-dark="isDark" />

        <div class="absolute right-6 top-6 z-20">
            <ThemeToggle />
        </div>

        <div class="relative z-10 w-full max-w-md">
            <div
                class="neon-frame rounded-3xl p-[1.5px]"
            >
                <div
                    class="rounded-[22px] p-8 backdrop-blur-2xl transition-colors duration-300"
                    :class="isDark ? 'bg-[#0B1220]/90' : 'bg-white/90'"
                >
                    <div class="mb-6 flex flex-col items-center text-center">
                        <img
                            src="/logo.png"
                            alt="CLASSLY"
                            class="mb-4 h-16 w-16 drop-shadow-[0_0_20px_rgba(37,99,235,0.55)]"
                        />
                        <h1 class="text-2xl font-extrabold tracking-tight">CLASSLY</h1>
                        <p class="mt-1 text-sm font-medium text-[#2563EB]" :class="isDark && '!text-[#5B9CFF]'">
                            Class Scheduling and Management System
                        </p>
                    </div>

                    <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Welcome back</h2>
                    <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                        Sign in to access your CLASSLY dashboard.
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
                        <Message
                            v-if="form.errors.email"
                            severity="error"
                            :closable="false"
                            :class="isDark ? '!border-red-400/30 !bg-red-400/10 !text-red-200' : '!border-red-300 !bg-red-50 !text-red-700'"
                        >
                            {{ form.errors.email }}
                        </Message>

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
                        </div>

                        <div class="flex flex-col gap-2">
                            <label for="password" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Password</label>
                            <Password
                                id="password"
                                v-model="form.password"
                                :feedback="false"
                                toggleMask
                                autocomplete="current-password"
                                :inputClass="['w-full', inputClass]"
                                class="w-full"
                                :invalid="!!form.errors.password"
                                placeholder="••••••••"
                            />
                            <small v-if="form.errors.password" class="text-red-400">
                                {{ form.errors.password }}
                            </small>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <Checkbox v-model="form.remember" inputId="remember" binary />
                                <label for="remember" class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">Remember me</label>
                            </div>
                            <Link
                                :href="route('password.request')"
                                class="text-sm hover:underline"
                                :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'"
                            >
                                Forgot password?
                            </Link>
                        </div>

                        <Button
                            type="submit"
                            label="Login"
                            class="!w-full !border-[#2563EB]/60 !bg-[#2563EB] shadow-[0_8px_24px_rgba(37,99,235,0.45)]"
                            size="large"
                            :loading="form.processing"
                        />
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>