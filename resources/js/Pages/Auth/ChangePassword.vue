<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useTheme } from '@/composables/useTheme';

/**
 * SECURITY / PASSWORD POLICY — the full-page screen
 * EnsurePasswordIsCurrent redirects an authenticated user to when
 * either must_change_password is flagged on their account or their
 * password has expired (security.password_expiry_days). See
 * ChangePasswordController for the props this renders and the update
 * action this form posts to.
 */
const props = defineProps({
    reason: {
        type: String, // 'required' | 'expired'
        required: true,
    },
    policyDescription: {
        type: String,
        default: '',
    },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.change.update'), {
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
};

const heading = computed(() =>
    props.reason === 'expired' ? 'Your password has expired' : 'Password change required'
);

const subheading = computed(() =>
    props.reason === 'expired'
        ? 'Your password has passed its expiry date. Set a new one to continue.'
        : 'An administrator requires you to set a new password before continuing.'
);

const inputClass = computed(() =>
    isDark.value
        ? '!border-white/15 !bg-white/[0.06] !text-white placeholder:!text-slate-500'
        : '!border-slate-900/10 !bg-white/70 !text-[#1E293B] placeholder:!text-slate-400'
);
</script>

<template>
    <Head title="Change Password" />

    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-6 py-16 transition-colors duration-300"
        :class="isDark ? 'bg-[#080D1A] text-white' : 'bg-gradient-to-br from-[#EEF2FF] via-[#F8FAFC] to-[#E0E7FF] text-[#1E293B]'"
    >
        <!-- Ambient glow field -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                class="absolute -top-40 -right-32 h-[26rem] w-[26rem] rounded-full blur-[110px] motion-safe:animate-[pulse_9s_ease-in-out_infinite]"
                :class="isDark ? 'bg-[#2563EB]/30' : 'bg-[#2563EB]/20'"
            />
            <div
                class="absolute bottom-[-8rem] -left-32 h-[26rem] w-[26rem] rounded-full blur-[110px] motion-safe:animate-[pulse_11s_ease-in-out_infinite]"
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
                <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ heading }}</h2>
                <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                    {{ subheading }}
                </p>
                <p v-if="policyDescription" class="mt-2 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-400'">
                    {{ policyDescription }}
                </p>

                <form class="mt-6 flex flex-col gap-5" @submit.prevent="submit">
                    <Message
                        v-if="form.errors.current_password"
                        severity="error"
                        :closable="false"
                        :class="isDark ? '!border-red-400/30 !bg-red-400/10 !text-red-200' : '!border-red-300 !bg-red-50 !text-red-700'"
                    >
                        {{ form.errors.current_password }}
                    </Message>

                    <div class="flex flex-col gap-2">
                        <label for="current_password" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                            Current Password
                        </label>
                        <Password
                            id="current_password"
                            v-model="form.current_password"
                            :feedback="false"
                            toggleMask
                            autocomplete="current-password"
                            :inputClass="['w-full', inputClass]"
                            class="w-full"
                            :invalid="!!form.errors.current_password"
                            placeholder="••••••••"
                            autofocus
                        />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="password" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">New Password</label>
                        <Password
                            id="password"
                            v-model="form.password"
                            toggleMask
                            autocomplete="new-password"
                            :inputClass="['w-full', inputClass]"
                            class="w-full"
                            :invalid="!!form.errors.password"
                            placeholder="••••••••"
                        />
                        <small v-if="form.errors.password" class="text-red-400">
                            {{ form.errors.password }}
                        </small>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="password_confirmation" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                            Confirm New Password
                        </label>
                        <Password
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            :feedback="false"
                            toggleMask
                            autocomplete="new-password"
                            :inputClass="['w-full', inputClass]"
                            class="w-full"
                            :invalid="!!form.errors.password_confirmation"
                            placeholder="••••••••"
                        />
                        <small v-if="form.errors.password_confirmation" class="text-red-400">
                            {{ form.errors.password_confirmation }}
                        </small>
                    </div>

                    <Button
                        type="submit"
                        label="Update Password"
                        class="!w-full !border-[#2563EB]/60 !bg-[#2563EB] shadow-[0_8px_24px_rgba(37,99,235,0.45)]"
                        size="large"
                        :loading="form.processing"
                    />
                </form>
            </div>
        </div>
    </div>
</template>