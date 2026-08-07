<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import ProgressBar from 'primevue/progressbar';
import Tag from 'primevue/tag';
import Button from 'primevue/button';

const props = defineProps({
    activeTerm: { type: Object, default: null },
    stats: { type: Object, required: true },
    alerts: { type: Object, required: true },
    collegeProgress: { type: Array, default: () => [] },
    topFaculty: { type: Array, default: () => [] },
    topRooms: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

const termLabel = computed(() => {
    if (!props.activeTerm) return 'No Active Academic Term';
    return `${props.activeTerm.school_year} | ${props.activeTerm.semester}`;
});

const alertItems = computed(() => {
    const items = [];
    if (props.alerts.no_faculty > 0) {
        items.push({
            text: `${props.alerts.no_faculty} subject${props.alerts.no_faculty === 1 ? '' : 's'} have no faculty assigned.`,
            href: route('scheduling.section-subjects'),
        });
    }
    if (props.alerts.no_room > 0) {
        items.push({
            text: `${props.alerts.no_room} subject${props.alerts.no_room === 1 ? '' : 's'} have no room assigned.`,
            href: route('scheduling.section-subjects'),
        });
    }
    if (props.alerts.faculty_overload > 0) {
        items.push({
            text: `${props.alerts.faculty_overload} faculty overload${props.alerts.faculty_overload === 1 ? '' : 's'} detected.`,
            href: route('scheduling.faculty'),
        });
    }
    if (props.alerts.room_conflicts > 0) {
        items.push({
            text: `${props.alerts.room_conflicts} room conflict${props.alerts.room_conflicts === 1 ? '' : 's'} found.`,
            href: route('scheduling.rooms'),
        });
    }
    if (props.alerts.sections_needing_scheduling > 0) {
        items.push({
            text: `${props.alerts.sections_needing_scheduling} section${props.alerts.sections_needing_scheduling === 1 ? '' : 's'} still require manual scheduling.`,
            href: route('scheduling.section-subjects'),
        });
    }
    return items;
});

const facultyBarColor = (units, max) => {
    if (!max) return '#94A3B8';
    const ratio = units / max;
    if (ratio > 1) return '#DC2626';
    if (ratio >= 0.85) return '#D97706';
    return '#2563EB';
};

const activityStatusSeverity = (status) => {
    if (status === 'Scheduled') return 'success';
    if (status === 'Conflict') return 'danger';
    return 'warn';
};
</script>

<template>
    <Head title="Scheduling Dashboard" />

    <AppLayout>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Scheduling Dashboard</h1>
                <p class="mt-1 max-w-2xl text-slate-500">
                    Monitor scheduling progress, detect conflicts, validate schedules, and manage the overall
                    scheduling process for the active semester.
                </p>
            </div>
            <span
                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm font-semibold text-slate-600"
            >
                <i class="pi pi-calendar text-[#2563EB]"></i>
                {{ termLabel }}
            </span>
        </div>

        <!-- Summary Stat Cards -->
        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <p class="text-2xl font-bold text-[#1E293B]">{{ stats.total_sections }}</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Sections</p>
                </template>
            </Card>
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <p class="text-2xl font-bold text-[#1E293B]">{{ stats.total_subjects }}</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Subjects</p>
                </template>
            </Card>
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <p class="text-2xl font-bold text-emerald-600">{{ stats.scheduled_subjects }}</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Successfully Scheduled</p>
                </template>
            </Card>
            <Link :href="route('scheduling.section-subjects')">
                <Card class="!rounded-2xl border border-slate-100 shadow-sm transition-colors hover:border-amber-200 hover:bg-amber-50/40">
                    <template #content>
                        <p class="text-2xl font-bold text-amber-600">{{ stats.remaining_subjects }}</p>
                        <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Needs Scheduling</p>
                    </template>
                </Card>
            </Link>
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <p class="text-2xl font-bold text-[#2563EB]">{{ stats.completion }}%</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Complete</p>
                </template>
            </Card>
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <p class="text-2xl font-bold text-[#1E293B]">{{ stats.active_rooms }}</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Rooms Used</p>
                </template>
            </Card>
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <p class="text-2xl font-bold text-[#1E293B]">{{ stats.active_faculty }}</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">Faculty Assigned</p>
                </template>
            </Card>
        </div>

        <!-- Scheduling Progress -->
        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-6">
            <template #title>
                <span class="text-base font-bold text-[#1E293B]">Scheduling Progress</span>
            </template>
            <template #content>
                <ProgressBar
                    :value="stats.completion"
                    :showValue="false"
                    style="height: 12px"
                    :pt="{ value: { style: { backgroundColor: '#2563EB' } } }"
                />
                <div class="mt-2 flex items-center justify-between text-sm">
                    <span class="text-slate-500">
                        {{ stats.scheduled_subjects }} of {{ stats.total_subjects }} subjects scheduled
                    </span>
                    <span class="font-semibold text-[#2563EB]">{{ stats.completion }}%</span>
                </div>
            </template>
        </Card>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- College Progress -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #title>
                    <span class="text-base font-bold text-[#1E293B]">College Progress</span>
                </template>
                <template #content>
                    <div v-if="collegeProgress.length === 0" class="text-sm text-slate-400">
                        No sections found for the active term.
                    </div>
                    <div v-for="college in collegeProgress" :key="college.id" class="mb-4 last:mb-0">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-700">{{ college.name }}</span>
                            <span class="font-semibold text-slate-500">{{ college.percent }}%</span>
                        </div>
                        <ProgressBar
                            :value="college.percent"
                            :showValue="false"
                            style="height: 8px"
                            :pt="{ value: { style: { backgroundColor: '#2563EB' } } }"
                        />
                    </div>
                </template>
            </Card>

            <!-- Scheduling Alerts -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #title>
                    <span class="text-base font-bold text-[#1E293B]">Scheduling Alerts</span>
                </template>
                <template #content>
                    <div v-if="alertItems.length === 0" class="flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                        <i class="pi pi-check-circle"></i>
                        No scheduling conflicts detected.
                    </div>
                    <Link
                        v-for="(alert, idx) in alertItems"
                        :key="idx"
                        :href="alert.href"
                        class="mb-2 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 transition-colors last:mb-0 hover:bg-amber-100"
                    >
                        <i class="pi pi-exclamation-triangle text-amber-500"></i>
                        {{ alert.text }}
                    </Link>
                </template>
            </Card>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Faculty Utilization -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #title>
                    <span class="text-base font-bold text-[#1E293B]">Faculty Utilization</span>
                </template>
                <template #content>
                    <div v-if="topFaculty.length === 0" class="text-sm text-slate-400">
                        No faculty assignments yet.
                    </div>
                    <div v-for="f in topFaculty" :key="f.name" class="mb-3 last:mb-0">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-700">{{ f.name }}</span>
                            <span class="text-slate-500">{{ f.units }} / {{ f.max }}</span>
                        </div>
                        <ProgressBar
                            :value="f.max ? Math.min(100, (f.units / f.max) * 100) : 0"
                            :showValue="false"
                            style="height: 7px"
                            :pt="{ value: { style: { backgroundColor: facultyBarColor(f.units, f.max) } } }"
                        />
                    </div>
                </template>
            </Card>

            <!-- Room Utilization -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #title>
                    <span class="text-base font-bold text-[#1E293B]">Room Utilization</span>
                </template>
                <template #content>
                    <div v-if="topRooms.length === 0" class="text-sm text-slate-400">
                        No room assignments yet.
                    </div>
                    <div v-for="r in topRooms" :key="r.name" class="mb-3 last:mb-0">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-700">{{ r.name }}</span>
                            <span class="text-slate-500">{{ r.occupancy }}%</span>
                        </div>
                        <ProgressBar
                            :value="r.occupancy"
                            :showValue="false"
                            style="height: 7px"
                            :pt="{ value: { style: { backgroundColor: '#2563EB' } } }"
                        />
                    </div>
                </template>
            </Card>
        </div>

        <!-- Quick Actions -->
        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-6">
            <template #title>
                <span class="text-base font-bold text-[#1E293B]">Quick Actions</span>
            </template>
            <template #content>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Link :href="route('scheduling.section-subjects')">
                        <Button
                            label="Generate All Schedules"
                            icon="pi pi-bolt"
                            class="!w-full !justify-start !bg-[#2563EB] !border-[#2563EB]"
                        />
                    </Link>
                    <Link :href="route('scheduling.section-subjects')">
                        <Button
                            label="Validate Schedules"
                            icon="pi pi-check-square"
                            severity="secondary"
                            outlined
                            class="!w-full !justify-start"
                        />
                    </Link>
                    <Link :href="route('scheduling.section-subjects')">
                        <Button
                            label="Detect Conflicts"
                            icon="pi pi-search"
                            severity="secondary"
                            outlined
                            class="!w-full !justify-start"
                        />
                    </Link>
                    <Button
                        label="Export Master Schedule"
                        icon="pi pi-download"
                        severity="secondary"
                        outlined
                        disabled
                        class="!w-full !justify-start"
                        v-tooltip="'Coming soon'"
                    />
                </div>
                <p class="mt-3 text-xs text-slate-400">
                    Generating, validating, and resolving schedules happens on the Section Subjects page — these
                    shortcuts take you straight there.
                </p>
            </template>
        </Card>

        <!-- Recent Scheduling Activity -->
        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-6">
            <template #title>
                <span class="text-base font-bold text-[#1E293B]">Recent Scheduling Activity</span>
            </template>
            <template #content>
                <div v-if="recentActivity.length === 0" class="text-sm text-slate-400">
                    No scheduling activity yet.
                </div>
                <div
                    v-for="(activity, idx) in recentActivity"
                    :key="idx"
                    class="flex items-center justify-between gap-3 border-b border-slate-100 py-3 text-sm last:border-b-0"
                >
                    <span class="text-slate-700">{{ activity.label }}</span>
                    <div class="flex flex-none items-center gap-3">
                        <Tag :value="activity.status" :severity="activityStatusSeverity(activity.status)" />
                        <span class="w-24 text-right text-xs text-slate-400">{{ activity.updated_at }}</span>
                    </div>
                </div>
            </template>
        </Card>
    </AppLayout>
</template>