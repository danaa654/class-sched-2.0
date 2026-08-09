<script setup>
import { Link } from '@inertiajs/vue3';

// Same shortcut set for every role in Phase 1 — none of these routes
// are currently role-gated on the backend beyond normal auth (only
// /users is Administrator-only), so there's no visibility distinction
// to make here yet.
const actions = [
    { label: 'Generate Schedule', icon: 'pi-bolt', route: 'scheduling.section-subjects' },
    { label: 'Manage Sections', icon: 'pi-th-large', route: 'scheduling.sections' },
    { label: 'Manage Faculty', icon: 'pi-users', route: 'scheduling.faculty' },
    { label: 'Manage Rooms', icon: 'pi-building', route: 'scheduling.rooms' },
    { label: 'Open Reports', icon: 'pi-chart-bar', route: 'reports' },
];
</script>

<template>
    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
        <p class="mb-4 text-lg font-bold text-[#1E293B]">Quick Actions</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <Link
                v-for="action in actions"
                :key="action.route"
                :href="route(action.route)"
                class="flex flex-col items-center gap-2 rounded-xl border border-slate-100 px-3 py-4 text-center transition-colors hover:border-blue-200 hover:bg-blue-50"
            >
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <i :class="['pi', action.icon]"></i>
                </span>
                <span class="text-xs font-semibold text-slate-600">{{ action.label }}</span>
            </Link>
        </div>
    </div>
</template>