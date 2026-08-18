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

defineProps({
    isDark: { type: Boolean, default: false },
});
</script>

<template>
    <div class="neu-card rounded-2xl p-5 transition-colors duration-300">
        <p class="mb-4 text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Quick Actions</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <Link
                v-for="action in actions"
                :key="action.route"
                :href="route(action.route)"
                class="neu-card neu-card--clickable flex flex-col items-center gap-2 rounded-xl px-3 py-4 text-center"
            >
                <span
                    class="neu-icon-well neu-glow flex h-10 w-10 items-center justify-center rounded-full"
                    :class="isDark ? 'text-blue-400' : 'text-blue-600'"
                    :style="{ '--neu-glow-color': isDark ? 'rgba(56, 189, 248, 0.3)' : 'rgba(37, 99, 235, 0.3)' }"
                >
                    <i :class="['pi', action.icon]"></i>
                </span>
                <span class="text-xs font-semibold" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ action.label }}</span>
            </Link>
        </div>
    </div>
</template>