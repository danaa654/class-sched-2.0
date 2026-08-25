<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Checkbox from 'primevue/checkbox';
import Button from 'primevue/button';
import Message from 'primevue/message';

const props = defineProps({
    // Which face shows first: 'login' or 'forgot-password'.
    initialFace: {
        type: String,
        default: 'login',
    },
    isDark: {
        type: Boolean,
        default: false,
    },
    loginStatus: {
        type: String,
        default: null,
    },
    forgotStatus: {
        type: String,
        default: null,
    },
});

const flipped = ref(props.initialFace === 'forgot-password');

// Keep the card in sync if the page is reached fresh via a direct
// route visit (e.g. someone bookmarks /forgot-password).
watch(
    () => props.initialFace,
    (face) => {
        flipped.value = face === 'forgot-password';
    }
);

const showForgotPassword = () => {
    flipped.value = true;
};

const showLogin = () => {
    flipped.value = false;
};

const loginForm = useForm({
    email: '',
    password: '',
    remember: false,
});

const submitLogin = () => {
    loginForm.post(route('login'), {
        onFinish: () => loginForm.reset('password'),
    });
};

// LIVE RATE-LIMIT COUNTDOWN — the backend's "Too many login attempts.
// Please try again in :seconds seconds." message (see LoginRequest::
// ensureIsNotRateLimited()) is a static string frozen at the moment
// of the failed request; it never counted down on its own and the
// only way to see an updated number was to resubmit/refresh. This
// parses the seconds out of that message once, then ticks a local
// timer down every second and clears the error (re-enabling the
// form) the moment it reaches zero, without another request.
const throttleSecondsRemaining = ref(null);
let throttleInterval = null;

const parseThrottleSeconds = (message) => {
    const match = message?.match(/(\d+)\s+seconds?/);
    return match ? parseInt(match[1], 10) : null;
};

const clearThrottleCountdown = () => {
    if (throttleInterval) {
        clearInterval(throttleInterval);
        throttleInterval = null;
    }
    throttleSecondsRemaining.value = null;
};

const startThrottleCountdown = (seconds) => {
    clearThrottleCountdown();
    throttleSecondsRemaining.value = seconds;
    throttleInterval = setInterval(() => {
        if (throttleSecondsRemaining.value <= 1) {
            clearThrottleCountdown();
            loginForm.clearErrors('email');
        } else {
            throttleSecondsRemaining.value -= 1;
        }
    }, 1000);
};

watch(
    () => loginForm.errors.email,
    (message) => {
        const seconds = parseThrottleSeconds(message);
        if (seconds) {
            startThrottleCountdown(seconds);
        } else {
            clearThrottleCountdown();
        }
    }
);

onBeforeUnmount(clearThrottleCountdown);

// What the error banner actually shows: while the countdown is
// running, the live ticking count; otherwise, whatever the backend
// last sent (invalid credentials, validation errors, etc.).
const loginEmailError = computed(() =>
    throttleSecondsRemaining.value !== null
        ? `Too many login attempts. Please try again in ${throttleSecondsRemaining.value} second${throttleSecondsRemaining.value === 1 ? '' : 's'}.`
        : loginForm.errors.email
);

const forgotForm = useForm({
    email: '',
});

const submitForgot = () => {
    forgotForm.post(route('password.email'));
};

const inputClass = computed(() =>
    props.isDark
        ? '!text-white placeholder:!text-slate-500'
        : '!text-[#1E293B] placeholder:!text-slate-400'
);

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General. Kept separate from CLASSLY's own system
// branding (logo/name/tagline) shown above in each card face.
const page = usePage();
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });
</script>

<template>
    <div class="auth-flip-scene w-full max-w-md">
        <div class="auth-flip-card" :class="{ 'is-flipped': flipped }">
            <!-- Front face: Login -->
            <div class="auth-flip-face auth-flip-face--front">
                <div class="neu-card neu-spotlight rounded-3xl">
                    <div
                        class="auth-card-face-inner rounded-3xl p-8 transition-colors duration-300"
                    >
                        <div class="mb-6 flex flex-col items-center text-center">
                            <span
                                class="neu-inset neu-glow mb-4 flex h-24 w-24 items-center justify-center rounded-full"
                                :style="{ '--neu-glow-color': isDark ? 'rgba(56, 189, 248, 0.35)' : 'rgba(37, 99, 235, 0.3)' }"
                            >
                                <img src="/logo.png" alt="CLASSLY" class="h-14 w-14" />
                            </span>
                            <h1 class="text-2xl font-extrabold tracking-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">CLASSLY</h1>
                            <p class="mt-1 text-sm font-medium text-[#2563EB]" :class="isDark && '!text-[#5B9CFF]'">
                                Class Scheduling and Management System
                            </p>
                        </div>

                        <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Welcome back</h2>
                        <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                            Sign in to access your CLASSLY dashboard.
                        </p>

                        <Message
                            v-if="loginStatus"
                            severity="success"
                            :closable="false"
                            class="mt-5"
                            :class="isDark ? '!border-emerald-400/30 !bg-emerald-400/10 !text-emerald-200' : '!border-emerald-600/20 !bg-emerald-50 !text-emerald-700'"
                        >
                            {{ loginStatus }}
                        </Message>

                        <form class="mt-6 flex flex-col gap-5" @submit.prevent="submitLogin">
                            <Message
                                v-if="loginEmailError"
                                severity="error"
                                :closable="false"
                                :class="isDark ? '!border-red-400/30 !bg-red-400/10 !text-red-200' : '!border-red-300 !bg-red-50 !text-red-700'"
                            >
                                {{ loginEmailError }}
                            </Message>

                            <div class="flex flex-col gap-2">
                                <label for="email" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Email</label>
                                <InputText
                                    id="email"
                                    v-model="loginForm.email"
                                    type="email"
                                    autocomplete="username"
                                    :autofocus="!flipped"
                                    :class="['w-full neu-inset !rounded-xl !border-none', inputClass]"
                                    :invalid="!!loginForm.errors.email"
                                    placeholder="you@classly.local"
                                />
                            </div>

                            <div class="flex flex-col gap-2">
                                <label for="password" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Password</label>
                                <Password
                                    id="password"
                                    v-model="loginForm.password"
                                    :feedback="false"
                                    toggleMask
                                    autocomplete="current-password"
                                    :inputClass="['w-full neu-inset !rounded-xl !border-none', inputClass]"
                                    class="w-full"
                                    :invalid="!!loginForm.errors.password"
                                    placeholder="••••••••"
                                />
                                <small v-if="loginForm.errors.password" class="text-red-400">
                                    {{ loginForm.errors.password }}
                                </small>
                            </div>

                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <Checkbox v-model="loginForm.remember" inputId="remember" binary />
                                    <label for="remember" class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">Remember me</label>
                                </div>
                                <button
                                    type="button"
                                    class="text-sm hover:underline"
                                    :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'"
                                    @click="showForgotPassword"
                                >
                                    Forgot password?
                                </button>
                            </div>

                            <Button
                                type="submit"
                                :label="throttleSecondsRemaining !== null ? `Try again in ${throttleSecondsRemaining}s` : 'Login'"
                                class="!w-full !border-[#2563EB]/60 !bg-[#2563EB] shadow-[0_8px_24px_rgba(37,99,235,0.45)]"
                                size="large"
                                :loading="loginForm.processing"
                                :disabled="throttleSecondsRemaining !== null"
                            />
                        </form>
                    </div>
                </div>
            </div>

            <!-- Back face: Forgot password -->
            <div class="auth-flip-face auth-flip-face--back">
                <div class="neu-card neu-spotlight rounded-3xl">
                    <div
                        class="auth-card-face-inner rounded-3xl p-8 transition-colors duration-300"
                    >
                        <div class="mb-6 flex flex-col items-center text-center">
                            <span
                                class="neu-inset neu-glow mb-4 flex h-24 w-24 items-center justify-center rounded-full"
                                :style="{ '--neu-glow-color': isDark ? 'rgba(56, 189, 248, 0.35)' : 'rgba(37, 99, 235, 0.3)' }"
                            >
                                <img src="/logo.png" alt="CLASSLY" class="h-14 w-14" />
                            </span>
                            <h1 class="text-2xl font-extrabold tracking-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">CLASSLY</h1>
                            <p class="mt-1 text-sm font-medium text-[#2563EB]" :class="isDark && '!text-[#5B9CFF]'">
                                Class Scheduling and Management System
                            </p>
                        </div>

                        <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Forgot your password?</h2>
                        <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                            Enter your email and we'll send you a link to reset it.
                        </p>

                        <Message
                            v-if="forgotStatus"
                            severity="success"
                            :closable="false"
                            class="mt-5"
                            :class="isDark ? '!border-emerald-400/30 !bg-emerald-400/10 !text-emerald-200' : '!border-emerald-600/20 !bg-emerald-50 !text-emerald-700'"
                        >
                            {{ forgotStatus }}
                        </Message>

                        <form class="mt-6 flex flex-col gap-5" @submit.prevent="submitForgot">
                            <div class="flex flex-col gap-2">
                                <label for="forgot-email" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Email</label>
                                <InputText
                                    id="forgot-email"
                                    v-model="forgotForm.email"
                                    type="email"
                                    autocomplete="username"
                                    :autofocus="flipped"
                                    :class="['w-full neu-inset !rounded-xl !border-none', inputClass]"
                                    :invalid="!!forgotForm.errors.email"
                                    placeholder="you@classly.local"
                                />
                                <small v-if="forgotForm.errors.email" class="text-red-400">
                                    {{ forgotForm.errors.email }}
                                </small>
                            </div>

                            <Button
                                type="submit"
                                label="Email Password Reset Link"
                                class="!w-full !border-[#2563EB]/60 !bg-[#2563EB] shadow-[0_8px_24px_rgba(37,99,235,0.45)]"
                                size="large"
                                :loading="forgotForm.processing"
                            />

                            <button
                                type="button"
                                class="text-center text-sm transition-colors"
                                :class="isDark ? 'text-slate-400 hover:text-[#5B9CFF]' : 'text-slate-500 hover:text-[#2563EB]'"
                                @click="showLogin"
                            >
                                Back to login
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- School branding footer (Settings → General) — separate from CLASSLY's own branding above -->
        <p
            v-if="schoolBranding.name"
            class="mt-6 text-center text-xs"
            :class="isDark ? 'text-slate-500' : 'text-slate-400'"
        >
            &copy; 2026 {{ schoolBranding.name }} — by Classly @ DJS
        </p>
    </div>
</template>