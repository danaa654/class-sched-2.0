<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from 'primevue/card';

const props = defineProps({
    conflicts: {
        type: Object,
        required: true,
        // { faculty_conflicts, room_conflicts, time_conflicts,
        //   unscheduled_subjects, missing_faculty, missing_rooms }
    },
    isDark: { type: Boolean, default: false },
});

// Every conflict type currently routes to the Section Subjects
// (scheduling workspace) list — there's no per-conflict-type filtered
// view yet, so this is the closest existing page where the Registrar
// can act on any of these. Worth a dedicated filtered view later.
const items = computed(() => [
    { key: 'faculty_conflicts', label: 'Faculty Conflicts', icon: 'pi-user' },
    { key: 'room_conflicts', label: 'Room Conflicts', icon: 'pi-building' },
    { key: 'time_conflicts', label: 'Time Conflicts', icon: 'pi-clock' },
    { key: 'unscheduled_subjects', label: 'Unscheduled Subjects', icon: 'pi-calendar-times' },
    { key: 'missing_faculty', label: 'Missing Faculty', icon: 'pi-user-minus' },
    { key: 'missing_rooms', label: 'Missing Rooms', icon: 'pi-map-marker' },
]);

const totalIssues = computed(() =>
    Object.values(props.conflicts).reduce((sum, count) => sum + count, 0)
);

const cardPt = computed(() => ({
    body: { class: props.isDark ? '!bg-transparent' : '' },
}));
</script>

<template>
    <div class="neu-card rounded-2xl">
        <Card
            class="!rounded-2xl !bg-transparent transition-colors duration-300"
            :pt="cardPt"
        >
        <template #title>
            <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Attention Needed</span>
        </template>
        <template #content>
            <div
                v-if="totalIssues === 0"
                class="neu-inset neu-glow flex items-center gap-3 rounded-xl px-4 py-5"
                :style="{ '--neu-glow-color': isDark ? 'rgba(52, 211, 153, 0.25)' : 'rgba(16, 185, 129, 0.25)' }"
            >
                <i class="pi pi-check-circle text-2xl" :class="isDark ? 'text-emerald-400' : 'text-emerald-600'"></i>
                <p class="font-medium" :class="isDark ? 'text-emerald-300' : 'text-emerald-800'">No scheduling conflicts detected.</p>
            </div>

            <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="item in items"
                    :key="item.key"
                    class="rounded-xl"
                    :class="conflicts[item.key] > 0 ? 'neu-card neu-glow' : 'neu-inset'"
                    :style="conflicts[item.key] > 0 ? { '--neu-glow-color': isDark ? 'rgba(248, 113, 113, 0.25)' : 'rgba(220, 38, 38, 0.2)' } : {}"
                >
                    <Link
                        :href="route('scheduling.section-subjects')"
                        class="flex items-center gap-3 rounded-xl p-4 transition-colors"
                    >
                        <span
                            class="neu-icon-well flex h-10 w-10 flex-none items-center justify-center rounded-full"
                            :class="conflicts[item.key] > 0
                                ? (isDark ? 'text-red-400' : 'text-red-600')
                                : (isDark ? 'text-slate-400' : 'text-slate-400')"
                        >
                            <i :class="['pi', item.icon]"></i>
                        </span>
                        <div>
                            <p class="text-xs font-semibold tracking-wide uppercase" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ item.label }}</p>
                            <p
                                class="text-xl font-bold"
                                :class="conflicts[item.key] > 0 ? (isDark ? 'text-red-400' : 'text-red-700') : (isDark ? 'text-white' : 'text-[#1E293B]')"
                            >
                                {{ conflicts[item.key] }}
                            </p>
                        </div>
                    </Link>
                </div>
            </div>
        </template>
        </Card>
    </div>
</template>