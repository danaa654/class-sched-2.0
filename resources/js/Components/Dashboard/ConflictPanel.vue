<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Card from 'primevue/card';
import Dialog from 'primevue/dialog';
import InfoPopover from '@/Components/InfoPopover.vue';

const props = defineProps({
    conflicts: {
        type: Object,
        required: true,
        // { faculty_conflicts, faculty_conflicts_detail, room_conflicts,
        //   room_conflicts_detail, time_conflicts, time_conflicts_detail,
        //   unscheduled_subjects, major_subjects_scheduled, major_subjects_total,
        //   minor_gened_subjects_scheduled, minor_gened_subjects_total,
        //   major_subjects_by_program, minor_gened_subjects_by_program:
        //   [{ program_code, program_name, scheduled, total }]
        //   *_conflicts_detail: [{ type, section_a, subject_a, section_b,
        //   subject_b, day, time, note, open_section_id }] }
    },
    roles: {
        type: Array,
        default: () => [],
        // Drives the "what can I actually do about this" guidance in
        // each item's popover — an Admin/Registrar can create Faculty/
        // Room records directly, everyone else can only assign what
        // already exists (or submit a request), so the fix-it text is
        // tailored per role instead of giving Deans instructions they
        // can't follow.
    },
    isDark: { type: Boolean, default: false },
});

// Widest capability the user's roles grant, mirroring
// DashboardService::scopeFor() on the backend — Administrator/
// Registrar can manage Faculty and Room master data directly, a
// Dean/OIC/Assistant Dean can only assign what already exists within
// their own scope (see AGENTS notes: "Dean/OIC/Assistant Dean are
// view-only for room and faculty master data but retain scheduling
// access").
const canManageMasterData = computed(() =>
    props.roles.includes('Administrator') || props.roles.includes('Registrar')
);

const isScopedRole = computed(() =>
    props.roles.some((r) => ['Dean', 'OIC', 'Assistant Dean'].includes(r))
);

// Faculty/Room/Time tiles open a "what is this conflict, exactly"
// breakdown dialog (detailKey) instead of navigating straight to the
// Section Subjects list — a bare count never says which two classes
// collided or why. Faculty Conflicts folds in Faculty Mismatch, Room
// Conflicts folds in Room Type Mismatch, and Time Conflicts folds in
// Hours Mismatch (see DashboardService::conflictSummary()), since all
// three are "something's wrong with the Faculty/Room/Time this class
// landed in", just non-blocking rather than a hard double-booking.
const items = computed(() => [
    {
        key: 'faculty_conflicts',
        label: 'Faculty Conflicts',
        icon: 'pi-user',
        detailKey: 'faculty_conflicts_detail',
        what: 'The same faculty member is placed on two classes that share a day and an overlapping time — they physically can\'t teach both. Also flags a manually-assigned faculty member who isn\'t qualified for or from the academic home of the subject they\'re teaching.',
        fix: canManageMasterData.value
            ? 'Open the affected section in Section Subjects and reassign one of the two classes to a different time or to another qualified faculty member.'
            : 'Within your scheduling access, move one of the two classes to a different time or reassign it to another available, qualified faculty member.',
    },
    {
        key: 'room_conflicts',
        label: 'Room Conflicts',
        icon: 'pi-building',
        detailKey: 'room_conflicts_detail',
        what: 'Either the same room is booked for two classes that share a day and an overlapping time, or a class sits in a room of the wrong type (a Lecture subject in a Laboratory, or vice versa).',
        fix: canManageMasterData.value
            ? 'Open the affected section in Section Subjects and move the class to a free room, a non-overlapping time, or a room of the right type.'
            : 'Move the affected class to a different available room or time within your scheduling access.',
    },
    {
        key: 'time_conflicts',
        label: 'Time Conflicts',
        icon: 'pi-clock',
        detailKey: 'time_conflicts_detail',
        what: 'Either one section has two of its own subjects scheduled at overlapping times, or a class\'s scheduled hours don\'t add up to what the subject requires per week.',
        fix: 'Open the affected section in Section Subjects and adjust the day/time so it no longer overlaps, or matches the subject\'s required weekly hours.',
    },
    {
        key: 'unscheduled_subjects',
        label: 'Unscheduled Subjects',
        icon: 'pi-calendar-times',
        what: 'A subject offering that exists in a section but hasn\'t been given a day and time yet, so it has no place in the timetable.',
        fix: 'Open Section Subjects and set a day and time for it, either manually or with Auto-Schedule.',
    },
    {
        key: 'major_subjects',
        label: 'Major Subjects',
        icon: 'pi-book',
        informational: true,
        fraction: { scheduled: 'major_subjects_scheduled', total: 'major_subjects_total' },
        byProgramKey: 'major_subjects_by_program',
        what: 'How many Major subject offerings in this scope are fully Scheduled out of the total — not a problem to fix, just progress.',
        fix: 'Tap this tile to see the breakdown per program.',
    },
    {
        key: 'minor_gened_subjects',
        label: 'Minor / GenEd Subjects',
        icon: 'pi-bookmark',
        informational: true,
        fraction: { scheduled: 'minor_gened_subjects_scheduled', total: 'minor_gened_subjects_total' },
        byProgramKey: 'minor_gened_subjects_by_program',
        what: 'How many Minor and General Education subject offerings in this scope are fully Scheduled out of the total — not a problem to fix, just progress.',
        fix: 'Tap this tile to see the breakdown per program.',
    },
]);

// Per-program breakdown dialog — opened by tapping a fraction tile
// instead of navigating straight to Section Subjects, since "12/40"
// on its own doesn't tell a Dean with several programs which one
// still needs work (e.g. CCS: BSCS/BSIT/BSCRIMFI/BSIS).
const breakdownDialogVisible = ref(false);
const breakdownDialogItem = ref(null);
const breakdownRows = computed(() => breakdownDialogItem.value
    ? (props.conflicts[breakdownDialogItem.value.byProgramKey] ?? [])
    : []);

const openBreakdown = (item) => {
    breakdownDialogItem.value = item;
    breakdownDialogVisible.value = true;
};

// Conflict-detail dialog — opened by tapping a Faculty/Room/Time
// tile. Lists every underlying issue (double-booking pairs, plus
// Room Type / Hours mismatches for the Room/Time tiles) instead of
// dropping the user straight into the full Section Subjects list.
const conflictDialogVisible = ref(false);
const conflictDialogItem = ref(null);
const conflictRows = computed(() => conflictDialogItem.value
    ? (props.conflicts[conflictDialogItem.value.detailKey] ?? [])
    : []);

const openConflictDetail = (item) => {
    conflictDialogItem.value = item;
    conflictDialogVisible.value = true;
};

// Mismatch-type rows (Room Type Mismatch / Hours Mismatch / Faculty
// Mismatch) describe only ONE class, not a colliding pair — the badge
// color also tells them apart from a hard double-booking at a glance.
const isMismatchType = (type) => type === 'Room Type Mismatch' || type === 'Hours Mismatch' || type === 'Faculty Mismatch';

// A tile either reads a single conflict count (conflicts[item.key]) or,
// for fraction tiles, conflicts[item.fraction.scheduled]. Used for the
// "everything's clear" check below — fraction/informational tiles are
// excluded, since they're progress, not problems.
const valueFor = (item) => (item.fraction ? props.conflicts[item.fraction.scheduled] : props.conflicts[item.key]);

// Informational tiles (progress, not problems) are excluded from both
// the "everything's clear" check and the red/attention styling below
// — only genuine conflicts/gaps should ever turn this panel red.
const totalIssues = computed(() =>
    items.value
        .filter((item) => !item.informational)
        .reduce((sum, item) => sum + (props.conflicts[item.key] ?? 0), 0)
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
            <div class="flex items-center gap-1.5">
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Attention Needed</span>
                <InfoPopover
                    title="Attention Needed"
                    :paragraphs="[
                        'Each tile below is a different kind of scheduling problem. Tap the small info icon on a tile to see what it means and how to fix it.',
                        isScopedRole
                            ? 'Counts here are scoped to your own college / subject area — conflicts belonging to other colleges are not shown.'
                            : 'Counts here are institution-wide, across every college.',
                    ]"
                    width="w-72"
                />
            </div>
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
                    class="relative rounded-xl"
                    :class="(!item.informational && valueFor(item) > 0) ? 'neu-card neu-glow' : 'neu-inset'"
                    :style="(!item.informational && valueFor(item) > 0) ? { '--neu-glow-color': isDark ? 'rgba(248, 113, 113, 0.25)' : 'rgba(220, 38, 38, 0.2)' } : {}"
                >
                    <!-- Stops the card-wide Link below from firing when the info icon is used. -->
                    <span class="absolute right-2 top-2 z-10" @click.stop.prevent @mousedown.stop>
                        <InfoPopover
                            :title="item.label"
                            :paragraphs="[item.what]"
                            :bullets="[item.fix]"
                            width="w-72"
                            :aria-label="`What does ${item.label} mean?`"
                        />
                    </span>

                    <Link
                        v-if="!item.byProgramKey && !item.detailKey"
                        :href="route('scheduling.section-subjects')"
                        class="flex items-center gap-3 rounded-xl p-4 pr-10 transition-colors"
                    >
                        <span
                            class="neu-icon-well flex h-10 w-10 flex-none items-center justify-center rounded-full"
                            :class="(!item.informational && valueFor(item) > 0)
                                ? (isDark ? 'text-red-400' : 'text-red-600')
                                : (isDark ? 'text-slate-400' : 'text-slate-400')"
                        >
                            <i :class="['pi', item.icon]"></i>
                        </span>
                        <div>
                            <p class="text-xs font-semibold tracking-wide uppercase" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ item.label }}</p>
                            <p
                                class="text-xl font-bold"
                                :class="(!item.informational && valueFor(item) > 0) ? (isDark ? 'text-red-400' : 'text-red-700') : (isDark ? 'text-white' : 'text-[#1E293B]')"
                            >
                                <template v-if="item.fraction">
                                    {{ conflicts[item.fraction.scheduled] }}<span class="text-base font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-400'"> / {{ conflicts[item.fraction.total] }}</span>
                                </template>
                                <template v-else>
                                    {{ conflicts[item.key] }}
                                </template>
                            </p>
                        </div>
                    </Link>

                    <!-- Tiles with a per-program breakdown open the drill-down
                         dialog instead of navigating to Section Subjects. -->
                    <button
                        v-else-if="item.byProgramKey"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl p-4 pr-10 text-left transition-colors"
                        @click="openBreakdown(item)"
                    >
                        <span
                            class="neu-icon-well flex h-10 w-10 flex-none items-center justify-center rounded-full"
                            :class="isDark ? 'text-slate-400' : 'text-slate-400'"
                        >
                            <i :class="['pi', item.icon]"></i>
                        </span>
                        <div>
                            <p class="text-xs font-semibold tracking-wide uppercase" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ item.label }}</p>
                            <p class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                                {{ conflicts[item.fraction.scheduled] }}<span class="text-base font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-400'"> / {{ conflicts[item.fraction.total] }}</span>
                            </p>
                        </div>
                    </button>

                    <!-- Faculty/Room/Time tiles open the conflict-detail
                         dialog instead of navigating to Section Subjects. -->
                    <button
                        v-else
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl p-4 pr-10 text-left transition-colors"
                        @click="openConflictDetail(item)"
                    >
                        <span
                            class="neu-icon-well flex h-10 w-10 flex-none items-center justify-center rounded-full"
                            :class="valueFor(item) > 0
                                ? (isDark ? 'text-red-400' : 'text-red-600')
                                : (isDark ? 'text-slate-400' : 'text-slate-400')"
                        >
                            <i :class="['pi', item.icon]"></i>
                        </span>
                        <div>
                            <p class="text-xs font-semibold tracking-wide uppercase" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ item.label }}</p>
                            <p
                                class="text-xl font-bold"
                                :class="valueFor(item) > 0 ? (isDark ? 'text-red-400' : 'text-red-700') : (isDark ? 'text-white' : 'text-[#1E293B]')"
                            >
                                {{ conflicts[item.key] }}
                            </p>
                        </div>
                    </button>
                </div>
            </div>
        </template>
        </Card>

        <Dialog
            v-model:visible="breakdownDialogVisible"
            modal
            :header="breakdownDialogItem ? `${breakdownDialogItem.label} — by Program` : ''"
            class="w-full sm:!w-[28rem]"
        >
            <p class="mb-3 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                Scheduled vs. total subject offerings, per program, within your scope.
            </p>

            <div v-if="breakdownRows.length" class="flex flex-col gap-2">
                <div
                    v-for="row in breakdownRows"
                    :key="row.program_code"
                    class="flex items-center justify-between rounded-lg px-3 py-2"
                    :class="isDark ? 'bg-white/5' : 'bg-slate-100'"
                >
                    <div>
                        <p class="font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ row.program_code }}</p>
                        <p class="text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ row.program_name }}</p>
                    </div>
                    <p class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                        {{ row.scheduled }}<span class="text-sm font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-400'"> / {{ row.total }}</span>
                    </p>
                </div>
            </div>
            <p v-else class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                No offerings of this type in your scope yet.
            </p>

            <template #footer>
                <Link
                    :href="route('scheduling.section-subjects')"
                    class="text-sm font-medium"
                    :class="isDark ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-700'"
                    @click="breakdownDialogVisible = false"
                >
                    Open Section Subjects →
                </Link>
            </template>
        </Dialog>

        <Dialog
            v-model:visible="conflictDialogVisible"
            modal
            :header="conflictDialogItem ? conflictDialogItem.label : ''"
            class="w-full sm:!w-[32rem]"
        >
            <p class="mb-3 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                {{ conflictDialogItem?.what }}
            </p>

            <div v-if="conflictRows.length" class="flex max-h-[60vh] flex-col gap-2 overflow-y-auto">
                <div
                    v-for="(row, idx) in conflictRows"
                    :key="idx"
                    class="rounded-lg px-3 py-2.5"
                    :class="isDark ? 'bg-white/5' : 'bg-slate-100'"
                >
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="isMismatchType(row.type)
                                ? (isDark ? 'bg-amber-400/20 text-amber-300' : 'bg-amber-100 text-amber-700')
                                : (isDark ? 'bg-red-400/20 text-red-300' : 'bg-red-100 text-red-700')"
                        >
                            {{ row.type }}
                        </span>
                        <span class="text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            {{ row.day }}<span v-if="row.day && row.time"> · </span>{{ row.time }}
                        </span>
                    </div>
                    <p class="text-sm" :class="isDark ? 'text-slate-200' : 'text-[#1E293B]'">{{ row.note }}</p>
                    <Link
                        v-if="row.open_section_id"
                        :href="route('scheduling.section-subjects.show', row.open_section_id)"
                        class="mt-1.5 inline-block text-xs font-medium"
                        :class="isDark ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-700'"
                        @click="conflictDialogVisible = false"
                    >
                        Open {{ row.section_a }} in Section Subjects →
                    </Link>
                </div>
            </div>
            <div
                v-else
                class="neu-inset flex items-center gap-3 rounded-xl px-4 py-5"
            >
                <i class="pi pi-check-circle text-xl" :class="isDark ? 'text-emerald-400' : 'text-emerald-600'"></i>
                <p class="text-sm font-medium" :class="isDark ? 'text-emerald-300' : 'text-emerald-800'">None of these detected in your scope.</p>
            </div>
        </Dialog>
    </div>
</template>