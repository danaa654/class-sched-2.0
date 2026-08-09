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
</script>

<template>
    <Card class="!rounded-2xl border border-slate-100 shadow-sm">
        <template #title>
            <span class="text-lg font-bold text-[#1E293B]">Attention Needed</span>
        </template>
        <template #content>
            <div v-if="totalIssues === 0" class="flex items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-5">
                <i class="pi pi-check-circle text-2xl text-emerald-600"></i>
                <p class="font-medium text-emerald-800">No scheduling conflicts detected.</p>
            </div>

            <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="item in items"
                    :key="item.key"
                    :href="route('scheduling.section-subjects')"
                    class="flex items-center gap-3 rounded-xl border p-4 transition-colors"
                    :class="conflicts[item.key] > 0
                        ? 'border-red-100 bg-red-50 hover:bg-red-100'
                        : 'border-slate-100 hover:bg-slate-50'"
                >
                    <span
                        class="flex h-10 w-10 flex-none items-center justify-center rounded-full"
                        :class="conflicts[item.key] > 0 ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-400'"
                    >
                        <i :class="['pi', item.icon]"></i>
                    </span>
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ item.label }}</p>
                        <p
                            class="text-xl font-bold"
                            :class="conflicts[item.key] > 0 ? 'text-red-700' : 'text-[#1E293B]'"
                        >
                            {{ conflicts[item.key] }}
                        </p>
                    </div>
                </Link>
            </div>
        </template>
    </Card>
</template>