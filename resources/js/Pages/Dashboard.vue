<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import SchedulingProgressCard from '@/Components/Dashboard/SchedulingProgressCard.vue';
import ConflictPanel from '@/Components/Dashboard/ConflictPanel.vue';
import QuickActions from '@/Components/Dashboard/QuickActions.vue';
import PlaceholderWidget from '@/Components/Dashboard/PlaceholderWidget.vue';

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

// Placeholders for widgets planned but not built in this phase —
// swapping any of these for the real component later is a drop-in
// change, not a page redesign.
const upcomingWidgets = [
    { title: 'Scheduling by College', description: 'Per-college scheduling completion, for Registrar/Administrator.', icon: 'pi-sitemap' },
    { title: 'Faculty Workload Summary', description: 'Available / Near Maximum / Overloaded faculty counts.', icon: 'pi-user-edit' },
    { title: 'Room Utilization', description: 'Lecture room and laboratory usage rates.', icon: 'pi-percentage' },
    { title: 'Academic Calendar', description: 'Class hours, lunch break, and school days at a glance.', icon: 'pi-calendar' },
    { title: 'Recent Activity', description: 'The latest scheduling actions across the system.', icon: 'pi-history' },
    { title: 'AI Insights', description: 'Scheduling recommendations and anomaly detection.', icon: 'pi-sparkles' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <!-- No Active Academic Term — scheduling can't run yet -->
        <Link
            v-if="!activeAcademicTerm"
            :href="route('academic-calendar')"
            class="mb-6 flex items-start gap-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 transition-colors hover:bg-amber-100 group"
        >
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-600 group-hover:bg-amber-200">
                <i class="pi pi-exclamation-triangle text-lg"></i>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-amber-900">No Active Academic Term</p>
                <p class="mt-0.5 text-sm text-amber-800">
                    Admin and Registrar need to set the Active Academic Term (School Year, Semester, and Scheduling Preferences)
                    before scheduling can start.
                </p>
            </div>
            <span class="flex-none self-center text-sm font-semibold text-amber-700 group-hover:text-amber-900 whitespace-nowrap">
                Set Academic Term
                <i class="pi pi-arrow-right ml-1 text-xs"></i>
            </span>
        </Link>

        <!-- 1. Header -->
        <div class="mb-6 flex flex-col gap-4 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-bold text-[#1E293B]">Welcome, {{ user.name }}</h1>
                <p class="mt-1 text-slate-500">
                    <span class="font-semibold text-[#2563EB]">{{ roleLabel }}</span>
                    <span v-if="scope.label"> — {{ scope.label }}</span>
                </p>
            </div>
            <div v-if="activeAcademicTermLabel" class="flex items-center gap-2 self-start rounded-full border border-slate-200 bg-slate-50 px-4 py-2 sm:self-auto">
                <i class="pi pi-calendar text-slate-400"></i>
                <span class="text-sm font-semibold text-slate-600">{{ activeAcademicTermLabel }}</span>
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
            />
            <KpiCard
                label="Faculty Members"
                :value="kpis.faculty_members"
                icon="pi-users"
                color="emerald"
                href="scheduling.faculty"
            />
            <KpiCard
                label="Available Rooms"
                :value="kpis.rooms"
                icon="pi-building"
                color="violet"
                href="scheduling.rooms"
            />
            <KpiCard
                label="Scheduled Subjects"
                :value="kpis.scheduled_subjects"
                :value-suffix="`/ ${kpis.total_subjects}`"
                icon="pi-calendar-plus"
                color="amber"
                href="scheduling.section-subjects"
            />
        </div>

        <!-- 3. Scheduling Progress + 4. Conflict Detection -->
        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <SchedulingProgressCard :progress="progress" />
            <ConflictPanel :conflicts="conflicts" />
        </div>

        <!-- 9. Quick Actions -->
        <div class="mb-6">
            <QuickActions />
        </div>

        <!-- Placeholders for widgets planned for later phases -->
        <div>
            <p class="mb-3 text-sm font-semibold tracking-wide text-slate-400 uppercase">More widgets, coming soon</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <PlaceholderWidget
                    v-for="widget in upcomingWidgets"
                    :key="widget.title"
                    :title="widget.title"
                    :description="widget.description"
                    :icon="widget.icon"
                />
            </div>
        </div>
    </AppLayout>
</template>