<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import SchedulingProgressCard from '@/Components/Dashboard/SchedulingProgressCard.vue';
import ConflictPanel from '@/Components/Dashboard/ConflictPanel.vue';
import QuickActions from '@/Components/Dashboard/QuickActions.vue';
import InfoPopover from '@/Components/InfoPopover.vue';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    overview: {
        type: Object,
        required: true,
        // { scope, kpis, progress, conflicts } — see DashboardService::overview()
    },
});

const page = usePage();
const user = page.props.auth.user;

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General (single source of truth).
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

/**
 * An Administrator required this user to change their password (User
 * Management > "Require password change on next login"). Rather than
 * relying only on the middleware's forced redirect, the Dashboard
 * also greets them with a blocking prompt on load pointing straight
 * at the right Manage Account tab — Administrators manage their own
 * account from User Management, everyone else from Settings (see
 * UsersController::updateAccount()).
 */
function promptPasswordChangeIfRequired() {
    if (!user?.must_change_password) return;

    const isAdministrator = props.roles.includes('Administrator');
    const target = isAdministrator ? route('users', { tab: 'account' }) : route('settings', { tab: 'account' });

    Swal.fire({
        icon: 'warning',
        title: 'Password Change Required',
        text: 'An Administrator requires you to change your password before continuing.',
        confirmButtonText: 'Change Password',
        confirmButtonColor: '#2563EB',
        allowOutsideClick: false,
        allowEscapeKey: false,
    }).then((result) => {
        if (result.isConfirmed) {
            router.visit(target);
        }
    });
}

// Live clock — ticks every second while the Dashboard is mounted.
const now = ref(new Date());
let clockInterval = null;

onMounted(() => {
    clockInterval = setInterval(() => {
        now.value = new Date();
    }, 1000);

    promptPasswordChangeIfRequired();
});

onUnmounted(() => {
    if (clockInterval) clearInterval(clockInterval);
});

const currentDateLabel = computed(() =>
    now.value.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }),

);
const currentTimeLabel = computed(() =>
    now.value.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
);

// Shared on every page by HandleInertiaRequests — the Academic Term
// currently marked Active, if any. Scheduling can't run without one,
// so the Dashboard nudges Admins/Registrars to set it up.
const activeAcademicTerm = computed(() => page.props.activeAcademicTerm);
const activeAcademicTermLabel = computed(() => {
    const term = activeAcademicTerm.value;
    if (!term) return null;

    return [term.school_year?.name, term.semester?.name].filter(Boolean).join(' • ');
});

const kpis = computed(() => props.overview.kpis);
const progress = computed(() => props.overview.progress);
const conflicts = computed(() => props.overview.conflicts);
const scope = computed(() => props.overview.scope);

const roleLabel = computed(() => props.roles.join(', ') || 'No role assigned');
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout v-slot="{ isDark }">
        <!-- No Active Academic Term — scheduling can't run yet -->
        <Link
            v-if="!activeAcademicTerm"
            :href="route('academic-calendar')"
            class="mb-6 flex items-start gap-4 rounded-2xl border px-5 py-4 transition-colors group"
            :class="isDark
                ? 'border-amber-400/25 bg-amber-500/10 hover:bg-amber-500/15'
                : 'border-amber-200 bg-amber-50 hover:bg-amber-100'"
        >
            <span
                class="flex h-10 w-10 flex-none items-center justify-center rounded-full"
                :class="isDark ? 'bg-amber-400/15 text-amber-400 group-hover:bg-amber-400/25' : 'bg-amber-100 text-amber-600 group-hover:bg-amber-200'"
            >
                <i class="pi pi-exclamation-triangle text-lg"></i>
            </span>
            <div class="flex-1">
                <p class="font-semibold" :class="isDark ? 'text-amber-300' : 'text-amber-900'">No Active Academic Term</p>
                <p class="mt-0.5 text-sm" :class="isDark ? 'text-amber-200/80' : 'text-amber-800'">
                    Admin and Registrar need to set the Active Academic Term (School Year, Semester, and Scheduling Preferences)
                    before scheduling can start.
                </p>
            </div>
            <span
                class="flex-none self-center text-sm font-semibold whitespace-nowrap"
                :class="isDark ? 'text-amber-300 group-hover:text-amber-200' : 'text-amber-700 group-hover:text-amber-900'"
            >
                Set Academic Term
                <i class="pi pi-arrow-right ml-1 text-xs"></i>
            </span>
        </Link>

        <!-- 1. Header -->
        <div class="neu-card neu-spotlight mb-6 rounded-2xl">
            <div
                class="flex flex-col gap-4 rounded-2xl p-6 transition-colors duration-300 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div v-if="schoolBranding.name" class="mb-1 flex items-center gap-2">
                        <img
                            v-if="schoolBranding.logoUrl"
                            :src="schoolBranding.logoUrl"
                            alt=""
                            class="h-5 w-5 rounded-full object-cover"
                            @error="$event.target.style.display = 'none'"
                        />
                        <span class="text-xs font-semibold uppercase tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            {{ schoolBranding.name }}
                        </span>
                    </div>
                    <h1 class="text-xl font-bold flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                        Welcome, {{ user.name }}
                        <InfoPopover
                            title="Dashboard"
                            :paragraphs="[
                                'A quick overview of scheduling status scoped to your role — summary counts, scheduling progress, and any conflicts that need attention.',
                            ]"
                            :bullets="[
                                'The KPI cards reflect the active academic term shown at the top right.',
                                'Conflicts listed here are visual indicators only — the server always re-validates before anything is saved.',
                                'What you see is scoped to your role: a Dean/OIC sees only their college\'s data.',
                            ]"
                        />
                    </h1>
                    <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                        <span class="font-semibold" :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'">{{ roleLabel }}</span>
                        <span v-if="scope.label"> — {{ scope.label }}</span>
                    </p>
                    <p class="mt-2 flex items-center gap-2 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                        <i class="pi pi-clock" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                        <span>{{ currentDateLabel }}</span>
                        <span class="opacity-50">•</span>
                        <span class="font-mono font-semibold tabular-nums" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ currentTimeLabel }}</span>
                    </p>
                </div>
                <div
                    v-if="activeAcademicTermLabel"
                    class="neu-inset neu-glow flex items-center gap-2 self-start rounded-full px-4 py-2 sm:self-auto"
                    :style="{ '--neu-glow-color': isDark ? 'rgba(52, 211, 153, 0.2)' : 'rgba(16, 185, 129, 0.2)' }"
                >
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                    </span>
                    <span class="text-sm font-semibold" :class="isDark ? 'text-emerald-300' : 'text-emerald-700'">Active</span>
                    <span class="h-3 w-px" :class="isDark ? 'bg-emerald-400/25' : 'bg-emerald-200'"></span>
                    <i class="pi pi-calendar" :class="isDark ? 'text-emerald-400/70' : 'text-emerald-500'"></i>
                    <span class="text-sm font-semibold" :class="isDark ? 'text-emerald-300' : 'text-emerald-700'">{{ activeAcademicTermLabel }}</span>
                </div>
            </div>
        </div>

        <!-- 2. Summary Cards -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                label="Active Sections"
                :value="kpis.active_sections"
                icon="pi-th-large"
                color="blue"
                href="scheduling.sections"
                :is-dark="isDark"
            />
            <KpiCard
                label="Faculty Members"
                :value="kpis.faculty_members"
                icon="pi-users"
                color="emerald"
                href="scheduling.faculty"
                :is-dark="isDark"
            />
            <KpiCard
                label="Available Rooms"
                :value="kpis.rooms"
                icon="pi-building"
                color="violet"
                href="scheduling.rooms"
                :is-dark="isDark"
            />
            <KpiCard
                label="Scheduled Subjects"
                :value="kpis.scheduled_subjects"
                :value-suffix="`/ ${kpis.total_subjects}`"
                icon="pi-calendar-plus"
                color="amber"
                href="scheduling.section-subjects"
                :is-dark="isDark"
            />
        </div>

        <!-- 3. Scheduling Progress + 4. Conflict Detection -->
        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <SchedulingProgressCard :progress="progress" :is-dark="isDark" />
            <ConflictPanel :conflicts="conflicts" :is-dark="isDark" />
        </div>

        <!-- 9. Quick Actions -->
        <div class="mb-6">
            <QuickActions :is-dark="isDark" />
        </div>
    </AppLayout>
</template>