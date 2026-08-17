<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import DatePicker from 'primevue/datepicker';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Toast from 'primevue/toast';
import Popover from 'primevue/popover';
import Drawer from 'primevue/drawer';
import ProgressBar from 'primevue/progressbar';
import Divider from 'primevue/divider';
import FacultyRecommendationSelector from '@/Components/Scheduling/FacultyRecommendationSelector.vue';
import RoomRecommendationSelector from '@/Components/Scheduling/RoomRecommendationSelector.vue';
import TimeRecommendationSelector from '@/Components/Scheduling/TimeRecommendationSelector.vue';
import MergeRecommendationModal from '@/Components/Scheduling/MergeRecommendationModal.vue';
import RoomGrid from '@/Components/Scheduling/RoomGrid.vue';
import { useTheme } from '@/composables/useTheme';
import { useSchedulePolling } from '@/composables/useSchedulePolling';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    section: { type: Object, required: true },
    // CONCURRENCY HARDENING — this Section's schedule_version at the
    // moment this page was loaded. Carried forward as
    // `expected_schedule_version` on every scheduling write (Save
    // Schedule / Accept All & Save) and used as the baseline for the
    // real-time change-detection poller below.
    scheduleVersion: { type: Number, default: 1 },
    // Every other Section this user can see for the same Academic
    // Year + Semester (Section::visibleTo() — same RBAC scope the
    // Sections list itself uses), powering the header's section
    // switcher so a Dean/OIC doesn't have to leave this page and
    // reopen the Sections list to move between e.g. every BSIT block.
    siblingSections: { type: Array, default: () => [] },
    sectionSubjects: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ subject_search: '' }) },
    curriculums: { type: Array, default: () => [] },
    availableSubjects: { type: Array, default: () => [] },
    yearLevelMap: { type: Object, default: () => ({}) },
    sectionYearLevel: { type: String, default: null },
    sectionSemester: { type: String, default: null },
    semesterOptions: { type: Array, default: () => [] },
    // Scheduling table options — Faculty/Room dropdowns.
    activeFaculty: { type: Array, default: () => [] },
    activeRooms: { type: Array, default: () => [] },
    schedulingWindow: {
        type: Object,
        default: () => ({ start_time: '08:00', end_time: '18:00', available_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] }),
    },
});

const toast = useToast();
const page = usePage();

// A Section whose section_name is identical to its section_code (e.g.
// both "BSHM-2A") would otherwise render duplicated — "BSHM-2A —
// BSHM-2A". Only append the name when it actually adds information,
// same convention RoomGrid.vue's roomLabel() already uses for Rooms.
const sectionLabel = (sec) => {
    if (!sec) return '';
    const code = (sec.section_code || '').trim();
    const name = (sec.section_name || '').trim();
    if (!name || name.toLowerCase() === code.toLowerCase()) return code;
    return `${code} — ${name}`;
};

/* ------------------------------------------------------------------ */
/* Section switcher — jump to a sibling Section without leaving the    */
/* page (header dropdown next to the Section name).                    */
/* ------------------------------------------------------------------ */

// Scheduling-progress status for a Section, matching the Sections
// list's own thresholds exactly (SectionController::index() /
// Sections/Index.vue): no rows at all -> "none"; rows exist but zero
// assigned -> "not_scheduled"; some but not all assigned ->
// "partial"; every row assigned -> "full". Drives the switcher
// dropdown's status dot so a Dean/OIC can see at a glance which
// sibling Sections still need attention without opening each one.
const sectionScheduleStatus = (sec) => {
    const total = sec.total_subjects_count ?? 0;
    const assigned = sec.assigned_subjects_count ?? 0;
    if (total === 0) return 'none';
    if (assigned === 0) return 'not_scheduled';
    if (assigned < total) return 'partial';
    return 'full';
};

const sectionStatusDotClass = (status) => ({
    full: 'bg-green-500',
    partial: 'bg-amber-500',
    not_scheduled: 'bg-slate-300',
    none: 'bg-slate-200',
}[status] ?? 'bg-slate-200');

const sectionSwitcherOptions = computed(() =>
    props.siblingSections.map((s) => ({
        id: s.id,
        label: sectionLabel(s),
        major: s.major?.code || s.major?.name || '',
        status: sectionScheduleStatus(s),
        totalSubjects: s.total_subjects_count ?? 0,
        assignedSubjects: s.assigned_subjects_count ?? 0,
    })),
);

const selectedSectionId = ref(props.section.id);
watch(
    () => props.section.id,
    (id) => {
        selectedSectionId.value = id;
    },
);

const currentSwitcherOption = computed(() =>
    sectionSwitcherOptions.value.find((o) => o.id === selectedSectionId.value) ?? null,
);

const onSwitchSection = (id) => {
    if (!id || id === props.section.id) return;
    router.visit(route('scheduling.section-subjects.show', id), {
        preserveScroll: true,
    });
};

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
        }
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
        }
    },
);

/* ------------------------------------------------------------------ */
/* Search                                                               */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.subject_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reload = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.section-subjects.show', props.section.id),
        { subject_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => reload(), 350);
});

const onRefresh = () => reload();

/* ------------------------------------------------------------------ */
/* Scheduling table — rows are a local editable copy of                */
/* sectionSubjects so Faculty/Room/Days/Time cells update instantly    */
/* without waiting on a full Inertia reload. Re-synced whenever the    */
/* server sends a fresh sectionSubjects prop (search, add, remove).    */
/* ------------------------------------------------------------------ */

const toDaysArray = (days) => (days ? days.split(',').filter(Boolean) : []);

const rows = ref(props.sectionSubjects.map((row) => ({ ...row, days: toDaysArray(row.days) })));

watch(
    () => props.sectionSubjects,
    (fresh) => {
        rows.value = fresh.map((row) => ({ ...row, days: toDaysArray(row.days) }));
    },
);

// Per-row, per-field validation error + saving indicator, keyed by row id.
const rowState = reactive({});
const stateFor = (rowId) => {
    if (!rowState[rowId]) {
        rowState[rowId] = { errors: {}, capacityConfirmed: false, workloadConfirmed: false, hoursConfirmed: false };
    }
    return rowState[rowId];
};

/* --- Days --- */

// Full reference list — every day the app knows about, in calendar
// order. This is NOT what's offered in the dropdown; see dayOptions
// below. Kept for formatDays()/orderedDayTokens, which need to know
// the full display order regardless of which days are selectable
// this School Year.
const allDayDefinitions = [
    { label: 'Monday', value: 'Mon' },
    { label: 'Tuesday', value: 'Tue' },
    { label: 'Wednesday', value: 'Wed' },
    { label: 'Thursday', value: 'Thu' },
    { label: 'Friday', value: 'Fri' },
    { label: 'Saturday', value: 'Sat' },
];

// ACADEMIC-CALENDAR-DRIVEN CLASS DAYS — the Days dropdown (and its
// quick-pick presets below) must only ever offer days the active
// School Year's Academic Calendar actually allows (schedulingWindow.
// available_days, sourced server-side from SchoolYear::allowedDays()
// — see SectionSubjectController::show()). Previously this list was
// hardcoded to all 7 days, so e.g. Saturday stayed pickable in the
// dropdown even when the active calendar was Mon–Fri only; the
// server-side check still caught it on Save ("Sat is not an allowed
// class day..."), but only after the Registrar had already picked it.
// This keeps the dropdown itself in sync with that same source of
// truth instead of just failing validation after the fact.
const dayOptions = computed(() => {
    const allowed = props.schedulingWindow?.available_days;
    if (!Array.isArray(allowed) || allowed.length === 0) {
        return allDayDefinitions;
    }
    return allDayDefinitions.filter((day) => allowed.includes(day.value));
});

// Quick-pick common combinations shown above the multi-select list.
// Meetings/Week is capped at 2x (see MultiSelect's selectionLimit),
// so only single- and double-day presets are offered here. Filtered
// the same way as dayOptions above — a preset is only offered when
// every day it contains is actually allowed this School Year (e.g.
// "SAT" disappears entirely once Saturday isn't a class day).
const allDayPresetDefinitions = [
    { label: 'MW', value: ['Mon', 'Wed'] },
    { label: 'TTH', value: ['Tue', 'Thu'] },
    { label: 'WF', value: ['Wed', 'Fri'] },
    { label: 'SAT', value: ['Sat'] },
];
const dayPresets = computed(() => {
    const allowed = props.schedulingWindow?.available_days;
    if (!Array.isArray(allowed) || allowed.length === 0) {
        return allDayPresetDefinitions;
    }
    return allDayPresetDefinitions.filter((preset) => preset.value.every((day) => allowed.includes(day)));
});

// Compact display like "MWF", "TTH", "SAT" instead of PrimeVue's
// default comma/chip rendering.
const dayAbbreviations = { Mon: 'M', Tue: 'T', Wed: 'W', Thu: 'TH', Fri: 'F', Sat: 'SAT' };
const orderedDayTokens = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const formatDays = (selected) => {
    if (!selected || selected.length === 0) return '';
    return orderedDayTokens
        .filter((token) => selected.includes(token))
        .map((token) => dayAbbreviations[token])
        .join('');
};

const applyDayPreset = (row, preset) => {
    onDaysChange(row, [...preset.value]);
};

/* --- Faculty — every active faculty member, grouped so those            */
/* qualified to teach the subject float to the top, but any active        */
/* faculty can still be picked manually (Registrar override).             */

const isQualifiedFor = (faculty, subject) => {
    if (!subject) return false;
    if (subject.category === 'General Education' && faculty.faculty_category === 'General Education Faculty') {
        return true;
    }
    return faculty.qualified_subject_ids.includes(subject.id);
};

const facultyGroupsFor = (row) => {
    const subject = row.subject;
    const recs = recommendations[row.id]?.faculty?.recommendations ?? [];
    const recommendedIds = new Set(recs.map((r) => r.id));

    // Built from the ranked recommendation list itself (not
    // props.activeFaculty) so the order matches the engine's ranking,
    // not the alphabetical roster order.
    const recommended = recs
        .filter((r) => props.activeFaculty.some((f) => f.id === r.id))
        .map((r) => ({ label: r.name, value: r.id, confidence: r.confidence }));

    const qualified = [];
    const others = [];

    props.activeFaculty.forEach((faculty) => {
        if (recommendedIds.has(faculty.id)) return;
        const option = { label: faculty.full_name, value: faculty.id };
        if (isQualifiedFor(faculty, subject)) {
            qualified.push(option);
        } else {
            others.push(option);
        }
    });

    const groups = [];
    if (recommended.length) groups.push({ label: 'Recommended', items: recommended, isRecommended: true });
    if (qualified.length) groups.push({ label: 'Qualified for This Subject', items: qualified });
    if (others.length) groups.push({ label: 'Other Active Faculty (Manual Override)', items: others });
    return groups;
};

/* --- Rooms — every active room, grouped so the type that matches the    */
/* subject's hours (Laboratory rooms if it has lab hours, otherwise       */
/* Lecture rooms) floats to the top. Any active room can still be         */
/* picked manually.                                                       */

const roomGroupsFor = (row) => {
    const subject = row.subject;
    const wantsLaboratory = Number(subject?.laboratory_hours ?? 0) > 0;
    const typeMatch = wantsLaboratory ? 'Laboratory' : 'Lecture';

    const recs = recommendations[row.id]?.room?.recommendations ?? [];
    const recommendedIds = new Set(recs.map((r) => r.id));

    const recommended = recs
        .filter((r) => props.activeRooms.some((room) => room.id === r.id))
        .map((r) => ({ label: `${r.name} (${r.capacity})`, value: r.id, confidence: r.confidence }));

    const typeMatched = [];
    const others = [];

    props.activeRooms.forEach((room) => {
        if (recommendedIds.has(room.id)) return;
        const option = { label: `${room.room_code} — ${room.room_name} (${room.capacity})`, value: room.id };
        if (room.room_type === typeMatch) {
            typeMatched.push(option);
        } else {
            others.push(option);
        }
    });

    const groups = [];
    if (recommended.length) groups.push({ label: 'Recommended', items: recommended, isRecommended: true });
    if (typeMatched.length) {
        groups.push({ label: wantsLaboratory ? 'Laboratory Rooms' : 'Lecture Rooms', items: typeMatched });
    }
    if (others.length) groups.push({ label: 'Other Rooms', items: others });
    return groups;
};

/* --- Time pickers — DatePicker bound to a JS Date, converted to "HH:mm" on save --- */

const timeStringToDate = (value) => {
    if (!value) return null;
    const [hours, minutes] = value.split(':').map(Number);
    const date = new Date();
    date.setHours(hours, minutes, 0, 0);
    return date;
};

const dateToTimeString = (date) => {
    if (!date) return null;
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
};

const startTimeModel = (row) => timeStringToDate(row.start_time);
const endTimeModel = (row) => timeStringToDate(row.end_time);

/* --- Auto End Time — a Subject's weekly contact hours (Lecture +        */
/* Laboratory) are split evenly across however many Days are selected,   */
/* then added to Start Time. E.g. a 5-hour subject on 1 day runs 5       */
/* hours; on 2 days (M&Th) each session runs 2h30m. Recomputed whenever  */
/* Start Time or Days changes — the Registrar can still overwrite End    */
/* Time by hand afterward.                                               */

const weeklyContactHours = (row) => {
    const lecture = Number(row.subject?.lecture_hours ?? 0);
    const laboratory = Number(row.subject?.laboratory_hours ?? 0);
    return lecture + laboratory;
};

const computeAutoEndTime = (row) => {
    const totalHours = weeklyContactHours(row);
    const dayCount = row.days?.length ?? 0;

    if (!row.start_time || !totalHours || !dayCount) return null;

    const [startHours, startMinutes] = row.start_time.split(':').map(Number);
    const sessionMinutes = Math.round((totalHours / dayCount) * 60);
    const endTotalMinutes = startHours * 60 + startMinutes + sessionMinutes;

    const endHours = String(Math.floor(endTotalMinutes / 60) % 24).padStart(2, '0');
    const endMinutes = String(endTotalMinutes % 60).padStart(2, '0');

    return `${endHours}:${endMinutes}`;
};

// The curriculum's weekly contact hours, split evenly across however
// many Days are selected, is the MAXIMUM a single meeting may run —
// the Registrar can always trim a meeting shorter (e.g. only 4 of the
// curriculum's 5 hrs fit because of Room/Faculty availability), but
// never stretch it past what the curriculum actually calls for.
const maxSessionMinutes = (row) => {
    const totalHours = weeklyContactHours(row);
    const dayCount = row.days?.length ?? 0;
    if (!totalHours || !dayCount) return null;
    return Math.round((totalHours / dayCount) * 60);
};

const clampEndTimeToMax = (row) => {
    const cap = maxSessionMinutes(row);
    if (!cap || !row.start_time || !row.end_time) return;

    const [startHours, startMinutes] = row.start_time.split(':').map(Number);
    const [endHours, endMinutes] = row.end_time.split(':').map(Number);
    const actualMinutes = (endHours * 60 + endMinutes) - (startHours * 60 + startMinutes);

    if (actualMinutes > cap) {
        const cappedTotal = startHours * 60 + startMinutes + cap;
        const h = String(Math.floor(cappedTotal / 60) % 24).padStart(2, '0');
        const m = String(cappedTotal % 60).padStart(2, '0');
        row.end_time = `${h}:${m}`;
    }
};

const autoFillEndTime = (row) => {
    const computed = computeAutoEndTime(row);
    if (computed) {
        row.end_time = computed;
        markDirty(row, 'end_time');
    }
};

/* --- Status / conflict badge --- */

const statusSeverity = (status) => {
    switch (status) {
        case 'Scheduled':
            return 'success';
        case 'Conflict':
            return 'danger';
        default:
            return 'secondary';
    }
};

// True once at least one scheduling field is blocked by a Faculty,
// Room, or Time conflict — surfaced as a warning badge next to Status.
const hasActiveConflict = (row) => {
    const errors = stateFor(row.id).errors;
    return Boolean(errors.faculty_id || errors.room_id || errors.days || errors.start_time || errors.end_time);
};

/* ------------------------------------------------------------------ */
/* Real-time conflict detection (Prompt 8.5)                           */
/*                                                                       */
/* Recomputed on every keystroke/selection from the local `rows` copy — */
/* no server round trip — so the Conflict Panel, row highlighting, and  */
/* Status badges update immediately as the Registrar edits a cell.      */
/* This covers Faculty/Room/Section conflicts *within this section's    */
/* own table* (everything the Registrar can see and cause here); a      */
/* Faculty or Room clash against a *different* section is still caught  */
/* by the existing server-side check when "Save Schedule" is clicked,   */
/* since this workspace doesn't load every other section's schedule.    */
/* ------------------------------------------------------------------ */

const roomsById = computed(() => Object.fromEntries(props.activeRooms.map((room) => [room.id, room])));
const facultyById = computed(() => Object.fromEntries(props.activeFaculty.map((f) => [f.id, f])));

const daysOverlap = (a, b) => (a ?? []).some((day) => (b ?? []).includes(day));
const timeOverlap = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && bStart < aEnd;
const minutesBetweenTimes = (start, end) => {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    return eh * 60 + em - (sh * 60 + sm);
};

const rowIsSchedulable = (row) => row.days?.length > 0 && row.start_time && row.end_time;

// { faculty: Map<rowId, [{type, message, otherRowId}]>, ... } — built as
// a flat list of conflict entries, each naming the row(s) it applies to.
const tableConflicts = computed(() => {
    const list = [];
    const data = rows.value;

    for (let i = 0; i < data.length; i++) {
        const a = data[i];

        // Room Capacity Warning — independent of other rows.
        if (a.room_id && a.capacity) {
            const room = roomsById.value[a.room_id];
            if (room && Number(a.capacity) > Number(room.capacity)) {
                list.push({
                    type: 'capacity',
                    rowIds: [a.id],
                    label: 'Capacity Warning',
                    detail: `${a.subject?.subject_code ?? 'Subject'} — Section Capacity ${a.capacity}, Room Capacity ${room.capacity} (${room.room_code})`,
                });
            }
        }

        // Faculty/Room/Section Double-Booking — set on the row when a
        // manual Time override (see onTimeOverride) was applied
        // against a slot ScheduleConflictService already found
        // occupied elsewhere (a genuine hard_conflict, not just an
        // off-pattern/short-duration warning — see scoreArbitraryTime's
        // docblock). Unlike Hours Mismatch/Capacity above, this is
        // NEVER confirmable — the Registrar can still pick a different
        // Faculty/Room/Time, but this exact combination can never be
        // saved, matching the same hard-block treatment as an
        // in-table Section Conflict.
        if (a.auto_generated_meta?.time?.hard_conflict) {
            list.push({
                type: 'hard_conflict',
                rowIds: [a.id],
                label: 'Schedule Conflict',
                detail: `${a.subject?.subject_code ?? 'Subject'} — ${a.auto_generated_meta.time.override_reason ?? 'The selected Faculty, Room, or Section is already booked at this day/time.'}`,
            });
        }

        // Weekly Hours Mismatch Warning — mirrors the same check the
        // server runs on Save Schedule (SectionSubjectController).
        // Flexible by design: the Registrar is free to trim a
        // session shorter (or longer) than the Subject's declared
        // Lecture+Laboratory hours — e.g. Sibling Pattern Matching
        // deliberately copies a donor's ACTUAL saved duration even
        // when it diverges from the textbook hours — so this is a
        // confirmable warning, never a hard block. Surfacing it here
        // (rather than only on the server) is what lets the row
        // highlight and the Registrar confirm it up front instead of
        // hitting an opaque "Nothing saved" after clicking Save.
        //
        // Confirmation is THIS-SESSION ONLY (stateFor(...).hoursConfirmed).
        // A stale persisted `row.hours_confirmed=true` from a PAST save
        // is never trusted here — see the ROOT-CAUSE FIX note below for
        // why. It only reappears as unconfirmed if Days/Start/End Time
        // change again this session (see onDaysChange/onStartTimeChange/
        // onEndTimeChange, which reset the local confirmation flag).
        // ROOT-CAUSE FIX — this used to also require `!a.hours_confirmed`
        // (the PERSISTED flag from a prior save) before the mismatch was
        // even added to tableConflicts. That let an old confirmation —
        // saved back when this row's duration still matched the
        // Subject's required hours — permanently hide a NEW mismatch
        // (e.g. after the Subject's required hours were edited
        // elsewhere), even on a row nobody touched this session. Unlike
        // Capacity above (which always lists the current mismatch and
        // only checks confirmation state later, via
        // unconfirmedHoursRowIds/unconfirmedCapacityRowIds), Hours was
        // the only one of the two withconfirmed-and-forgotten. Now it
        // always reflects the CURRENT numbers — confirmation status
        // (fresh, this-session only) is checked exactly once, later, by
        // unconfirmedHoursRowIds — matching how Capacity already works.
        if (rowIsSchedulable(a) && !stateFor(a.id).hoursConfirmed) {
            const required = weeklyContactHours(a) > 0 ? weeklyContactHours(a) : 3;
            const dayCount = a.days.length;
            const actual = Math.round(((minutesBetweenTimes(a.start_time, a.end_time) * dayCount) / 60) * 100) / 100;

            if (actual !== required) {
                list.push({
                    type: 'hours',
                    rowIds: [a.id],
                    label: 'Hours Mismatch',
                    detail: `${a.subject?.subject_code ?? 'Subject'} — this schedule totals ${actual} hrs/week, but the subject's declared hours are ${required} hrs/week.`,
                });
            }
        }

        if (!rowIsSchedulable(a)) continue;

        for (let j = i + 1; j < data.length; j++) {
            const b = data[j];
            if (!rowIsSchedulable(b)) continue;
            if (!daysOverlap(a.days, b.days)) continue;
            if (!timeOverlap(a.start_time, a.end_time, b.start_time, b.end_time)) continue;

            // Section Conflict — this section can't be in two places at once,
            // regardless of Faculty/Room, any time two of its own rows overlap.
            list.push({
                type: 'section',
                rowIds: [a.id, b.id],
                label: 'Section Conflict',
                detail: `${a.subject?.subject_code ?? 'Subject'} overlaps ${b.subject?.subject_code ?? 'Subject'} — ${formatDays(a.days.filter((d) => b.days.includes(d)))} ${formatTimeRange(a.start_time, a.end_time)}`,
            });

            if (a.faculty_id && a.faculty_id === b.faculty_id) {
                const facultyName = facultyById.value[a.faculty_id]?.full_name ?? 'Faculty';
                list.push({
                    type: 'faculty',
                    rowIds: [a.id, b.id],
                    label: 'Faculty Conflict',
                    detail: `${facultyName} — ${formatDays(a.days.filter((d) => b.days.includes(d)))} ${formatTimeRange(a.start_time, a.end_time)}`,
                });
            }

            if (a.room_id && a.room_id === b.room_id) {
                const room = roomsById.value[a.room_id];
                list.push({
                    type: 'room',
                    rowIds: [a.id, b.id],
                    label: 'Room Conflict',
                    detail: `${room ? `${room.room_code} — ${room.room_name}` : 'Room'} — ${formatDays(a.days.filter((d) => b.days.includes(d)))} ${formatTimeRange(a.start_time, a.end_time)}`,
                });
            }
        }
    }

    return list;
});

const formatTimeRange = (start, end) => {
    const fmt = (value) => {
        if (!value) return '';
        const [h, m] = value.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
    };
    return `${fmt(start)} - ${fmt(end)}`;
};

// Rows with an unconfirmed Capacity Warning — these are the only
// conflict type that can be overridden by the Registrar.
const unconfirmedCapacityRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'capacity').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).capacityConfirmed),
);

// Rows with an unconfirmed Weekly Hours Mismatch — confirmable, same
// pattern as unconfirmedCapacityRowIds above.
const unconfirmedHoursRowIds = computed(() =>
    tableConflicts.value.filter((c) => c.type === 'hours').map((c) => c.rowIds[0]).filter((id) => !stateFor(id).hoursConfirmed),
);

// A row is "blocking" (true Conflict — Faculty/Room/Section) if it
// appears in any non-capacity conflict entry.
const blockingConflictRowIds = computed(
    () => new Set(tableConflicts.value.filter((c) => c.type !== 'capacity' && c.type !== 'hours').flatMap((c) => c.rowIds)),
);

const rowHasBlockingConflict = (rowId) => blockingConflictRowIds.value.has(rowId);

// A row counts as "in conflict" for red-highlight/click-to-resolve
// purposes if EITHER the client-side same-table check (Faculty/Room/
// Section overlap against another row in this section) fired, OR the
// server rejected it (e.g. outside allowed class hours, lunch break,
// or a Faculty/Room clash against a *different* section that this
// workspace can't see client-side). Capacity Warnings are excluded —
// those are confirmable, not a hard conflict.
const rowIsInConflict = (row) => rowHasBlockingConflict(row.id) || hasActiveConflict(row);

// Row-click-to-resolve should only fire when the Registrar clicks
// empty space on a conflicted row (e.g. the EDP Code / Subject Title
// area) — never when they're actually interacting with one of the
// row's own Faculty/Room/Days/Time/Recommend/Delete controls, or the
// click would fight with those controls' own handlers.
const isInteractiveTarget = (target) =>
    Boolean(target?.closest?.('button, a, input, textarea, .p-select, .p-multiselect, .p-datepicker, .p-popover, [role="button"]'));

const onRowClick = (event) => {
    if (!rowIsInConflict(event.data)) return;
    if (isInteractiveTarget(event.originalEvent?.target)) return;
    openRecommendDrawer(event.data);
};
const facultyConflictRowIds = computed(() => new Set(tableConflicts.value.filter((c) => c.type === 'faculty').flatMap((c) => c.rowIds)));
const roomConflictRowIds = computed(() => new Set(tableConflicts.value.filter((c) => c.type === 'room').flatMap((c) => c.rowIds)));
const sectionConflictRowIds = computed(() => new Set(tableConflicts.value.filter((c) => c.type === 'section').flatMap((c) => c.rowIds)));
const rowHasCapacityWarning = (rowId) => tableConflicts.value.some((c) => c.type === 'capacity' && c.rowIds.includes(rowId));

const conflictTooltip = (rowId) => {
    const clientMessages = tableConflicts.value
        .filter((c) => c.rowIds.includes(rowId))
        .map((c) => `${c.label}: ${c.detail}`);

    // Server-side errors (outside class hours, lunch break, or a
    // Faculty/Room clash against a section this workspace never
    // loaded) don't have a `tableConflicts` entry — surface them too
    // so the tooltip/click-to-resolve always reflects everything that
    // is actually blocking this row.
    const serverMessages = Object.values(stateFor(rowId).errors ?? {});

    return [...clientMessages, ...serverMessages].join('\n');
};

// Real-time display status — overrides the last-saved `status` value so
// the badge reflects the *current* (unsaved) local edit immediately.
const displayStatus = (row) => {
    if (rowHasBlockingConflict(row.id)) return 'Conflict';
    return row.status;
};

// True when a row has none of its core scheduling fields filled in
// yet (no Faculty, Room, Days, Start Time, or End Time). Distinct from
// a Conflict/capacity warning — an unscheduled row isn't wrong, it's
// just incomplete — so it gets its own subtler "needs scheduling"
// treatment instead of reading like a rendering bug or an error.
const rowIsUnscheduled = (row) => {
    // Practicum/OJT subjects have no Room/Days/Time to fill in and
    // Faculty is an optional Coordinator/Adviser — the row is complete
    // as soon as it exists, so it's never flagged "Not yet scheduled".
    if (row.subject?.subject_type === 'practicum') return false;

    return (
        !row.faculty_id &&
        !row.room_id &&
        (!row.days || row.days.length === 0) &&
        !row.start_time &&
        !row.end_time
    );
};

/* ------------------------------------------------------------------ */
/* Smart Assignment Recommendation Engine (Prompt 8.6)                 */
/*                                                                       */
/* Ranked Faculty/Room/Time suggestions per row, fetched from           */
/* RecommendationService via the server. Purely advisory — nothing      */
/* here ever writes to row.faculty_id / room_id / days / times on its   */
/* own; the Registrar must explicitly click "Use This" for each field.  */
/* ------------------------------------------------------------------ */

const recommendations = reactive({});

const recommendationStateFor = (rowId) => recommendations[rowId] ?? { loading: false, error: null, faculty: null, room: null, time: null };

const fetchRecommendations = async (row, force = false) => {
    const existing = recommendations[row.id];
    if (!force && (existing?.loading || existing?.faculty)) return;

    recommendations[row.id] = { ...(existing ?? {}), loading: true, error: null };

    try {
        const response = await fetch(route('scheduling.section-subjects.recommend', [props.section.id, row.id]), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Request failed');
        const data = await response.json();
        recommendations[row.id] = { loading: false, error: null, ...data };
    } catch (e) {
        recommendations[row.id] = { loading: false, error: 'Could not load recommendations for this subject.', faculty: null, room: null, time: null };
    }
};

const confidenceSeverity = (label) => {
    switch (label) {
        case 'Best Match':
            return 'success';
        case 'Good Match':
            return 'info';
        default:
            return 'warning';
    }
};

// Recommendation Score progress-bar color — mirrors the same
// Best Match / Good Match / Alternative bands the badge uses.
const scoreColor = (score) => {
    if (score >= 85) return '#16a34a';
    if (score >= 65) return '#2563eb';
    return '#d97706';
};

// "Use This" — the Registrar's explicit confirmation. Recommendations
// never auto-assign; these are the only paths that fill a field from
// a suggestion, and they're only ever triggered by a manual click.
const applyFacultyRecommendation = (row, rec) => onFacultyChange(row, rec.id);
const applyRoomRecommendation = (row, rec) => onRoomChange(row, rec.id);
const applyTimeRecommendation = (row, rec) => {
    row.days = [...rec.days];
    row.start_time = rec.start_time;
    row.end_time = rec.end_time;
    markDirty(row, 'days');
    markDirty(row, 'start_time');
    markDirty(row, 'end_time');
};

/* --- Inline "Suggested Time" popover (Days column) ------------------ */
/* Faculty/Room already surface a "Recommended" group right inside      */
/* their own dropdown; Time gets the same one-click treatment here      */
/* instead of requiring the Registrar to expand the row's full panel.   */

const helpPopover = ref(null);
const toggleHelp = (event) => helpPopover.value?.toggle(event);

const timePopover = ref(null);
const timePopoverRow = ref(null);

const toggleTimeSuggestions = (event, row) => {
    timePopoverRow.value = row;
    fetchRecommendations(row);
    timePopover.value?.toggle(event);
};

const applyTimeRecommendationFromPopover = (row, rec) => {
    applyTimeRecommendation(row, rec);
    timePopover.value?.hide();
};

/* --- Inline "Recommended" popovers (Faculty / Room columns) --------- */
/* Same one-click treatment as the Time popover above, so Faculty and   */
/* Room get the same sparkle-triggered quick-pick instead of requiring  */
/* the row's old expand panel.                                          */

const facultyPopover = ref(null);
const facultyPopoverRow = ref(null);
const toggleFacultySuggestions = (event, row) => {
    facultyPopoverRow.value = row;
    fetchRecommendations(row);
    facultyPopover.value?.toggle(event);
};
const applyFacultyRecommendationFromPopover = (row, rec) => {
    applyFacultyRecommendation(row, rec);
    facultyPopover.value?.hide();
};

const roomPopover = ref(null);
const roomPopoverRow = ref(null);
const toggleRoomSuggestions = (event, row) => {
    roomPopoverRow.value = row;
    fetchRecommendations(row);
    roomPopover.value?.toggle(event);
};
const applyRoomRecommendationFromPopover = (row, rec) => {
    applyRoomRecommendation(row, rec);
    roomPopover.value?.hide();
};

/* ------------------------------------------------------------------ */
/* Smart Schedule Recommendation Drawer (Prompt 8.7)                    */
/*                                                                       */
/* Right-side PrimeVue Drawer opened from a per-row "Recommend" button. */
/* Shows the same Faculty/Room/Time lists as the row's inline panel,    */
/* plus full-schedule Combined Recommendations built server-side by     */
/* RecommendationService (Faculty x Room x Time, all conflict-checked   */
/* via ScheduleConflictService). Reject = just close the Drawer; Apply  */
/* only ever populates the row locally — the Registrar still has to     */
/* click "Save Schedule" to persist anything.                           */
/* ------------------------------------------------------------------ */

const recommendDrawerVisible = ref(false);
const recommendDrawerRow = ref(null);

const openRecommendDrawer = (row) => {
    recommendDrawerRow.value = row;
    recommendDrawerVisible.value = true;
    fetchRecommendations(row, true);
};

const recommendDrawerState = computed(() => recommendationStateFor(recommendDrawerRow.value?.id));

const closeRecommendDrawer = () => {
    recommendDrawerVisible.value = false;
};

// Accept a Combined Recommendation — populates Faculty, Room, Days,
// Start Time, and End Time on the row in one click, exactly like
// applying all three individual recommendations at once.
const applyCombinedRecommendation = (row, combo) => {
    onFacultyChange(row, combo.faculty.id);
    onRoomChange(row, combo.room.id);
    row.days = [...combo.time.days];
    row.start_time = combo.time.start_time;
    row.end_time = combo.time.end_time;
    markDirty(row, 'days');
    markDirty(row, 'start_time');
    markDirty(row, 'end_time');
    closeRecommendDrawer();
};

const applyFacultyRecommendationFromDrawer = (row, rec) => {
    applyFacultyRecommendation(row, rec);
};
const applyRoomRecommendationFromDrawer = (row, rec) => {
    applyRoomRecommendation(row, rec);
};
const applyTimeRecommendationFromDrawer = (row, rec) => {
    applyTimeRecommendation(row, rec);
};

/* --- Manual scheduling — edits stay local until "Save Schedule" is      */
/* clicked. No per-cell auto-save; the Registrar can edit as many rows    */
/* as they like first. Every edited field just updates the local row     */
/* and marks it "dirty"; nothing hits the server until Save.              */

// Laravel refreshes the XSRF-TOKEN cookie on every response, unlike the
// <meta name="csrf-token"> tag which is frozen at initial page load and
// goes stale the moment the session's token rotates (e.g. right after
// login). Reading it fresh from the cookie for every request avoids
// spurious "CSRF token mismatch" errors without needing a page refresh.
const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

/* ------------------------------------------------------------------ */
/* REAL-TIME SCHEDULE CHANGE DETECTION — lightweight polling.          */
/*                                                                       */
/* UX/early-warning layer only. Every 15s (paused/slowed while this     */
/* tab is hidden), asks a tiny endpoint "has this Section's schedule    */
/* changed?" and, if so, marks the page stale — a dismissible banner    */
/* plus a disabled/"refresh first" state on Save Schedule / Accept All  */
/* & Save. Never auto-reloads, never touches in-progress edits or the   */
/* open Auto Schedule modal. The backend's own version check inside a   */
/* locked transaction (HTTP 409 SCHEDULE_VERSION_CONFLICT) remains the  */
/* only thing that actually blocks a stale write — see saveSchedule().  */
/* ------------------------------------------------------------------ */

const schedulePolling = useSchedulePolling({
    sectionId: () => props.section.id,
    initialVersion: () => props.scheduleVersion,
    fetchVersion: async (sectionId) => {
        const response = await fetch(route('scheduling.section-subjects.version', sectionId), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Version check failed');
        return response.json();
    },
    intervalMs: 15000,
});

schedulePolling.start();

// Header's "Refresh Schedule" action — re-fetches this Section's page
// via Inertia (same route, so it's a lightweight partial reload of
// props only) rather than a hard browser reload, then clears the
// stale flag once the fresh props have landed. If the Registrar has
// local unsaved edits (dirty rows, or the Auto Schedule review panel
// still open), confirm first so nothing is silently discarded.
const refreshingSchedule = ref(false);
const refreshSchedule = () => {
    const hasUnsavedWork = dirtyRowIds.value.size > 0 || autoSummaryVisible.value;

    const doRefresh = () => {
        refreshingSchedule.value = true;
        router.reload({
            onFinish: () => {
                refreshingSchedule.value = false;
                dirtyRowIds.value = new Set();
                autoSummaryVisible.value = false;
                autoSummary.value = null;
                schedulePolling.acceptVersion(props.scheduleVersion);
                schedulePolling.checkNow();
                toast.add({ severity: 'info', summary: 'Refreshed', detail: 'Showing the latest schedule.', life: 3500 });
            },
        });
    };

    if (!hasUnsavedWork) {
        doRefresh();
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: 'Refresh Schedule?',
        text: 'Refreshing will discard your unsaved changes. Continue?',
        showCancelButton: true,
        confirmButtonText: 'Refresh',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
    }).then((result) => {
        if (result.isConfirmed) doRefresh();
    });
};

// Section Subjects page top-level tab — "Subjects" (the existing
// scheduling spreadsheet) vs "Room Grid" (spec: room-centric
// drag-and-drop view of the exact same SectionSubject rows).
const pageTab = ref('subjects');

// Room Grid writes go straight through updateSchedule() (immediate
// save, not staged like the spreadsheet's dirty-row batch), so the
// fresh row it returns is merged into `rows` the same way Faculty/
// Room override selectors already do above.
const onRoomGridRowUpdated = (fresh) => {
    if (!fresh) return;
    const row = rows.value.find((r) => r.id === fresh.id);
    if (row) {
        Object.assign(row, { ...fresh, days: toDaysArray(fresh.days) });
    }
};

// Room Grid's own writeSchedule() already handles a stale drag-drop
// save (409 SCHEDULE_VERSION_CONFLICT) locally — this just makes sure
// that also flips the page-wide stale banner + disables Save
// Schedule, since a conflict discovered from the Room Grid tab means
// the Subjects tab's data is equally out of date.
const onScheduleStaleFromRoomGrid = (currentVersion) => {
    if (typeof currentVersion === 'number') {
        schedulePolling.backendVersion.value = currentVersion;
    }
    schedulePolling.isStale.value = true;
};

const dirtyRowIds = ref(new Set());
const hasUnsavedChanges = computed(() => dirtyRowIds.value.size > 0);

const markDirty = (row, field) => {
    dirtyRowIds.value.add(row.id);
    delete stateFor(row.id).errors[field];
};

const onFacultyChange = (row, value) => {
    row.faculty_id = value;
    markDirty(row, 'faculty_id');
    // A new Faculty means any previous Teaching Load Limit
    // confirmation no longer applies — must be re-confirmed.
    stateFor(row.id).workloadConfirmed = false;
};
const onRoomChange = (row, value) => {
    row.room_id = value;
    markDirty(row, 'room_id');
    // A new Room means any previous Capacity Warning confirmation no
    // longer applies — it must be re-confirmed against the new Room.
    stateFor(row.id).capacityConfirmed = false;
};
const onDaysChange = (row, value) => {
    row.days = value;
    markDirty(row, 'days');
    autoFillEndTime(row);
    clampEndTimeToMax(row);
    stateFor(row.id).hoursConfirmed = false;
    // BUG FIX — row.hours_confirmed is the PERSISTED flag loaded from
    // the server (true if a prior save confirmed an Hours Mismatch at
    // the OLD Days/Time). Only resetting the local session flag above
    // left this stale, so tableConflicts()'s guard
    // (`!stateFor(a.id).hoursConfirmed && !a.hours_confirmed`) kept
    // treating an edited row as still-confirmed against hours that no
    // longer apply — the Weekly Hours Mismatch warning silently never
    // fired, and Save Schedule would fail server-side with no visible
    // explanation. Must invalidate both flags together whenever Days
    // or Time changes.
    row.hours_confirmed = false;
};
const onStartTimeChange = (row, date) => {
    row.start_time = dateToTimeString(date);
    markDirty(row, 'start_time');
    autoFillEndTime(row);
    clampEndTimeToMax(row);
    stateFor(row.id).hoursConfirmed = false;
    row.hours_confirmed = false; // see onDaysChange's comment above
};
const onEndTimeChange = (row, date) => {
    row.end_time = dateToTimeString(date);
    markDirty(row, 'end_time');
    clampEndTimeToMax(row);
    stateFor(row.id).hoursConfirmed = false;
    row.hours_confirmed = false; // see onDaysChange's comment above
};

/* --- Save Schedule — validates every row client-side, then saves        */
/* every row in a single request/transaction on the server. Nothing is    */
/* saved unless every row passes. --- */

const savingSchedule = ref(false);

const validateRowsClientSide = () => {
    let valid = true;

    rows.value.forEach((row) => {
        const state = stateFor(row.id);
        state.errors = {};

        if (row.start_time && row.end_time && row.start_time >= row.end_time) {
            state.errors.end_time = 'End Time must be later than Start Time.';
            valid = false;
        }

        // A schedule slot must be fully filled in, or fully empty — no
        // half-assigned rows (e.g. Faculty picked but no Room/Days/Time).
        const filledCount = [row.faculty_id, row.room_id, row.days?.length > 0, row.start_time, row.end_time].filter(
            Boolean,
        ).length;

        if (filledCount > 0 && filledCount < 5) {
            if (!row.faculty_id) state.errors.faculty_id = 'Required to complete this schedule.';
            if (!row.room_id) state.errors.room_id = 'Required to complete this schedule.';
            if (!row.days || row.days.length === 0) state.errors.days = 'Required to complete this schedule.';
            if (!row.start_time) state.errors.start_time = 'Required to complete this schedule.';
            if (!row.end_time) state.errors.end_time = 'Required to complete this schedule.';
            valid = false;
        }
    });

    return valid;
};

const saveSchedule = async () => {
    if (rows.value.length === 0) {
        return;
    }

    // BLOCK STALE SAVES EARLY — the poller already told us the
    // backend moved past what this page loaded. This is only an
    // early warning (the transaction + version check below is still
    // the real guard), but there's no point letting the Registrar
    // fill out a Swal confirmation dialog for a save the server is
    // about to reject with 409 anyway — send them to Refresh first.
    if (schedulePolling.isStale.value) {
        toast.add({
            severity: 'warn',
            summary: 'Schedule changed',
            detail: 'Another user changed this schedule. Please refresh before saving.',
            life: 6000,
        });
        return;
    }

    if (!validateRowsClientSide()) {
        toast.add({
            severity: 'warn',
            summary: 'Fix the highlighted rows',
            detail: 'Each schedule needs Faculty, Room, Days, Start Time, and End Time filled in together — or all left empty.',
            life: 6000,
        });
        return;
    }

    // Faculty/Room/Section conflicts are hard blocks — never savable.
    // The Save button is already disabled in this state, but this is
    // the authoritative reject in case it's reached another way.
    if (blockingConflictRowIds.value.size > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Cannot Save Schedule',
            text: 'Cannot save because scheduling conflicts were detected. Resolve the Scheduling Issues listed above and try again.',
            confirmButtonColor: '#dc2626',
        });
        return;
    }

    // Room Capacity Warnings can be saved, but only once the Registrar
    // explicitly confirms each one.
    const stillUnconfirmed = unconfirmedCapacityRowIds.value;
    if (stillUnconfirmed.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Room Capacity Warning',
            html: tableConflicts.value
                .filter((c) => c.type === 'capacity' && stillUnconfirmed.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmed.forEach((rowId) => {
            stateFor(rowId).capacityConfirmed = true;
        });
    }

    // Weekly Hours Mismatch — also confirmable, not a hard block.
    // Intentional trims/extensions (e.g. copied from a sibling
    // section's manually-adjusted duration) are allowed; this just
    // makes sure the Registrar sees it before it's saved instead of
    // being blocked by the server with no visible reason.
    const stillUnconfirmedHours = unconfirmedHoursRowIds.value;
    if (stillUnconfirmedHours.length > 0) {
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Weekly Hours Mismatch',
            html: tableConflicts.value
                .filter((c) => c.type === 'hours' && stillUnconfirmedHours.includes(c.rowIds[0]))
                .map((c) => `<div class="text-left text-sm">${c.detail}</div>`)
                .join(''),
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
        });

        if (!result.isConfirmed) {
            return;
        }

        stillUnconfirmedHours.forEach((rowId) => {
            stateFor(rowId).hoursConfirmed = true;
        });
    }

    savingSchedule.value = true;

    const buildPayload = () =>
        rows.value.map((row) => ({
            id: row.id,
            faculty_id: row.faculty_id || null,
            room_id: row.room_id || null,
            days: row.days ?? [],
            start_time: row.start_time || null,
            end_time: row.end_time || null,
            capacity: row.capacity || null,
            capacity_confirmed: Boolean(stateFor(row.id).capacityConfirmed),
            workload_confirmed: Boolean(stateFor(row.id).workloadConfirmed),
            hours_confirmed: Boolean(stateFor(row.id).hoursConfirmed),
        }));

    const submit = async () => {
        const response = await fetch(route('scheduling.section-subjects.schedule.batch', props.section.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            // CONCURRENCY HARDENING — the version this page last knew
            // about. The backend re-checks this itself, under a row
            // lock, inside the save transaction (the real guard); this
            // is just what makes that check possible.
            body: JSON.stringify({ rows: buildPayload(), expected_schedule_version: schedulePolling.currentVersion.value }),
        });

        return { response, data: await response.json() };
    };

    try {
        let { response, data } = await submit();

        // SAVE CONFLICT RESPONSE (spec Section 13) — another user's
        // save committed and bumped schedule_version after this page
        // loaded (or since the last successful save/refresh). Nothing
        // was written server-side (the whole transaction rolled
        // back). Keep the Registrar's current UI/edits exactly as-is,
        // mark the page stale so Save is blocked until they refresh,
        // and surface it clearly instead of a generic error toast.
        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            if (typeof data.current_version === 'number') {
                schedulePolling.backendVersion.value = data.current_version;
            }
            schedulePolling.isStale.value = true;

            toast.add({
                severity: 'error',
                summary: 'Save prevented',
                detail: 'Another user changed this schedule while you were editing. Refresh to see the latest version, then re-apply your changes.',
                life: 8000,
            });
            savingSchedule.value = false;
            return;
        }

        // FACULTY WORKLOAD VALIDATION — "Save Schedule Validation".
        // The server rejects with 409 and lists every faculty member
        // who'd exceed their Maximum Teaching Load. Only an
        // Administrator can acknowledge and resubmit with
        // workload_confirmed=true per affected row.
        if (response.status === 409 && data.workload_warnings) {
            const warnings = Object.values(data.workload_warnings);
            const canOverride = Boolean(data.can_override);

            const result = await Swal.fire({
                icon: 'warning',
                title: '⚠ Teaching Load Limit Exceeded',
                html:
                    '<div class="text-left text-sm space-y-2">' +
                    warnings
                        .map(
                            (w) =>
                                `<div><strong>${w.faculty_name}</strong><br/>Current: ${w.current} / ${w.max} ${w.unit_label} &rarr; assigning ${w.subject_code} would bring it to ${w.projected} / ${w.max} ${w.unit_label}.</div>`,
                        )
                        .join('') +
                    (canOverride
                        ? '<p class="mt-2">Please resolve these conflicts or override manually.</p>'
                        : '<p class="mt-2 text-red-600">Only an Administrator may override this validation.</p>') +
                    '</div>',
                showCancelButton: canOverride,
                confirmButtonText: canOverride ? 'Override & Save' : 'OK',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
            });

            if (!canOverride || !result.isConfirmed) {
                savingSchedule.value = false;
                return;
            }

            Object.keys(data.workload_warnings).forEach((rowId) => {
                stateFor(Number(rowId)).workloadConfirmed = true;
            });

            ({ response, data } = await submit());
        }

        if (!response.ok) {
            // Errors are keyed by SectionSubject id — map each back to
            // its row so the table highlights exactly what to fix.
            if (data.errors) {
                Object.entries(data.errors).forEach(([rowId, fieldErrors]) => {
                    Object.assign(stateFor(Number(rowId)).errors, fieldErrors);
                });
            }

            toast.add({
                severity: 'error',
                summary: 'Nothing saved',
                detail: data.message ?? 'Some rows have validation errors.',
                life: 7000,
            });
            return;
        }

        rows.value.forEach((row) => {
            const fresh = data.sectionSubjects?.find((r) => r.id === row.id);
            if (fresh) {
                Object.assign(row, { ...fresh, days: toDaysArray(fresh.days) });
            }
        });
        dirtyRowIds.value = new Set();

        // SAVE SUCCESS — the backend incremented schedule_version by
        // exactly 1 as part of this same transaction; adopt it as the
        // new baseline so the next poll (and the next save) compares
        // against it, not the version this page loaded with.
        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'success', summary: 'Saved', detail: data.message ?? 'Schedule saved successfully.', life: 4000 });
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Could not save the schedule. Please check your connection and try again.',
            life: 6000,
        });
    } finally {
        savingSchedule.value = false;
    }
};

/* ------------------------------------------------------------------ */
/* ⚡ Auto Generate Schedule (Prompt 8.9)                               */
/*                                                                       */
/* Server-side, AutoScheduleService walks every currently unscheduled   */
/* subject and writes the best conflict-free Faculty/Room/Time it can   */
/* find (Status stays 'Draft', is_auto_generated = true) — it never     */
/* touches a row that already has an assignment. This panel just shows  */
/* the result and lets the Registrar Accept (Save), Regenerate, or      */
/* Clear it before anything is finalized.                               */
/* ------------------------------------------------------------------ */

const autoGenerating = ref(false);
const autoClearing = ref(false);
const autoSummaryVisible = ref(false);
const autoSummary = ref(null); // { total, scheduled, results, unresolved, message }

const applyFreshRows = (fresh) => {
    fresh.forEach((freshRow) => {
        const row = rows.value.find((r) => r.id === freshRow.id);
        if (row) {
            Object.assign(row, { ...freshRow, days: toDaysArray(freshRow.days) });
        }
    });
};

const hasAutoGeneratedRows = computed(() => rows.value.some((row) => row.is_auto_generated));

/* -------------------------------------------------------------- */
/* INTELLIGENT IRREGULAR SECTION SCHEDULING — "Merge Recommendation" */
/* modal. Opens from either a merged result ("Merge into BSIT-4A")   */
/* or an unresolved/independent one, always calling                  */
/* mergeRecommendation() fresh so the candidate list reflects        */
/* current data even if it was computed earlier during generate().   */
/* -------------------------------------------------------------- */

const isIrregularSection = computed(() => props.section.section_type === 'Irregular');
const mergeModalVisible = ref(false);
const mergeModalLoading = ref(false);
const mergeModalApplying = ref(false);
const mergeModalResult = ref(null); // the result/unresolved item the modal was opened for
const mergeModalRecommendation = ref(null); // { recommendation, best_match, candidates, independent_reason }

const openMergeModal = async (item) => {
    mergeModalResult.value = item;
    mergeModalRecommendation.value = item.merge_recommendation ?? null;
    mergeModalVisible.value = true;
    mergeModalLoading.value = true;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge-recommendation', [props.section.id, item.section_subject_id]),
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not load merge recommendation.');
        mergeModalRecommendation.value = data;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not load merge recommendation.', life: 6000 });
    } finally {
        mergeModalLoading.value = false;
    }
};

const chooseMergeCandidate = async (candidate) => {
    if (!mergeModalResult.value) return;

    mergeModalApplying.value = true;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge', [props.section.id, mergeModalResult.value.section_subject_id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ target_section_subject_id: candidate.section_subject_id }),
            },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply merge.');

        applyFreshRows(data.sectionSubjects ?? []);
        mergeModalVisible.value = false;
        toast.add({ severity: 'success', summary: 'Merged', detail: data.message, life: 5000 });

        // Refresh the panel's own copy of the result so the "Merge into
        // X" summary updates without needing to re-run Auto Generate.
        if (autoSummary.value) {
            const merged = data.sectionSubjects.find((r) => r.id === mergeModalResult.value.section_subject_id);
            if (merged) {
                mergeModalResult.value.is_merged = true;
                mergeModalResult.value.merged_into_section_code = candidate.section_code;
            }
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not apply merge.', life: 6000 });
    } finally {
        mergeModalApplying.value = false;
    }
};

const chooseIndependentSchedule = async () => {
    if (!mergeModalResult.value) return;

    mergeModalApplying.value = true;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.schedule-independently', [props.section.id, mergeModalResult.value.section_subject_id]),
            { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() } },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not switch to an independent schedule.');

        applyFreshRows(data.sectionSubjects ?? []);
        mergeModalVisible.value = false;
        toast.add({ severity: 'info', summary: 'Independent Schedule', detail: data.message, life: 5000 });

        // Refresh the panel's own copy of the result — same pattern as
        // chooseMergeCandidate() above — so the "Auto Schedule
        // Complete" card immediately switches from the "Merged into
        // X" summary to the normal editable Faculty/Room/Time card
        // (with the Merge Recommendation link still available below
        // it), instead of continuing to show a stale "Merged" tag
        // until the panel is reopened.
        if (autoSummary.value) {
            const freshRow = data.sectionSubjects?.find((r) => r.id === mergeModalResult.value.section_subject_id);
            if (freshRow) {
                mergeModalResult.value.is_merged = false;
                mergeModalResult.value.merged_into_section_code = null;
                mergeModalResult.value.pattern_source = null;
                mergeModalResult.value.faculty = freshRow.faculty
                    ? { id: freshRow.faculty.id, name: freshRow.faculty.full_name }
                    : null;
                mergeModalResult.value.room = freshRow.room
                    ? { id: freshRow.room.id, name: `${freshRow.room.room_code} — ${freshRow.room.room_name}` }
                    : null;
                mergeModalResult.value.time = {
                    days: toDaysArray(freshRow.days),
                    start_time: freshRow.start_time,
                    end_time: freshRow.end_time,
                };
            }
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not switch to an independent schedule.', life: 6000 });
    } finally {
        mergeModalApplying.value = false;
    }
};

const runAutoGenerate = async () => {
    if (rows.value.length === 0) return;

    autoGenerating.value = true;

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            // CONCURRENCY HARDENING — captured as `generated_from_version`
            // server-side; if another user's change already landed since
            // this page loaded, the backend rejects with 409 below
            // instead of silently generating against stale data.
            body: JSON.stringify({ expected_schedule_version: schedulePolling.currentVersion.value }),
        });
        const data = await response.json();

        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            if (typeof data.current_version === 'number') schedulePolling.backendVersion.value = data.current_version;
            schedulePolling.isStale.value = true;
            toast.add({
                severity: 'error',
                summary: 'Schedule changed',
                detail: 'Another user changed this schedule. Please refresh before running Auto Schedule.',
                life: 7000,
            });
            return;
        }

        if (!response.ok) throw new Error(data.message ?? 'Auto generate failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        autoSummary.value = data;
        autoSummaryVisible.value = true;

        // This action itself just wrote to the database and advanced
        // schedule_version — adopt the new baseline immediately so the
        // next poll doesn't mistake our OWN write for someone else's
        // change and falsely lock out "Accept All & Save".
        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({
            severity: data.scheduled === data.total ? 'success' : 'warn',
            summary: 'Auto Schedule Complete',
            detail: data.message,
            life: 6000,
        });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not auto-generate the schedule.', life: 6000 });
    } finally {
        autoGenerating.value = false;
    }
};

const regenerateAutoSchedule = async () => {
    autoGenerating.value = true;

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate.regenerate', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ expected_schedule_version: schedulePolling.currentVersion.value }),
        });
        const data = await response.json();

        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            if (typeof data.current_version === 'number') schedulePolling.backendVersion.value = data.current_version;
            schedulePolling.isStale.value = true;
            toast.add({
                severity: 'error',
                summary: 'Schedule changed',
                detail: 'Another user changed this schedule. Please refresh before regenerating.',
                life: 7000,
            });
            return;
        }

        if (!response.ok) throw new Error(data.message ?? 'Regenerate failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        autoSummary.value = data;

        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Schedule Regenerated', detail: data.message, life: 6000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not regenerate the schedule.', life: 6000 });
    } finally {
        autoGenerating.value = false;
    }
};

const clearAutoSchedule = async () => {
    autoClearing.value = true;

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate.clear', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Clear failed.');

        applyFreshRows(data.sectionSubjects ?? []);
        autoSummary.value = null;
        autoSummaryVisible.value = false;

        // "Clear" also writes (reverts rows to empty) and bumps the
        // version — same reasoning as runAutoGenerate() above.
        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Cleared', detail: data.message, life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not clear the auto-generated schedule.', life: 6000 });
    } finally {
        autoClearing.value = false;
    }
};

// Closing the review panel (✕ button, ESC, or clicking outside)
// WITHOUT clicking "Accept All & Save" must not leave anything
// behind — the generated rows (and any manual Faculty/Room overrides
// made while the panel was open) are already persisted as Draft the
// instant they happen, so "close without accepting" has to actively
// discard them via the same endpoint "Clear Generated Schedule" uses,
// not just hide the dialog. Only fires for a user-initiated close
// (the ✕ icon / ESC / mask click all emit @update:visible); the
// programmatic `autoSummaryVisible.value = false` inside
// acceptAutoSchedule() does not re-enter this handler, so an actual
// Accept is never undone.
const onAutoSummaryVisibleChange = async (visible) => {
    if (visible) {
        autoSummaryVisible.value = true;
        return;
    }

    autoSummaryVisible.value = false;

    if (!hasAutoGeneratedRows.value) {
        autoSummary.value = null;
        return;
    }

    try {
        const response = await fetch(route('scheduling.section-subjects.auto-generate.clear', props.section.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Clear failed.');

        applyFreshRows(data.sectionSubjects ?? []);

        if (typeof data.schedule_version === 'number') {
            schedulePolling.acceptVersion(data.schedule_version);
        }

        toast.add({ severity: 'info', summary: 'Not Saved', detail: 'The generated schedule was discarded — nothing was saved.', life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not discard the generated schedule.', life: 6000 });
    } finally {
        autoSummary.value = null;
    }
};

// "Accept All" — the generated rows are already persisted as Draft;
// this simply runs the normal Save Schedule flow (same conflict
// re-check, same batch endpoint) to finalize them to 'Scheduled'.
const acceptAutoSchedule = async () => {
    await saveSchedule();
    autoSummaryVisible.value = false;
    autoSummary.value = null;
};

// Faculty Recommendation Selector (Prompt 8.11) — the override is
// already persisted server-side by the time this fires; this just
// refreshes the review panel's in-memory summary (Faculty block +
// Overall Score) so the Registrar sees the Live Score update
// instantly, with no modal close and no re-fetch of the whole panel.
const onFacultyOverride = (result, { faculty, overall_score }) => {
    result.faculty = faculty;
    if (overall_score !== undefined && overall_score !== null) {
        result.overall_score = overall_score;
    }

    const row = rows.value.find((r) => r.id === result.section_subject_id);
    if (row) {
        row.faculty_id = faculty.id;
        if (row.faculty) row.faculty = { ...row.faculty, id: faculty.id, full_name: faculty.name };
        // A new Faculty may have resolved (or introduced) a Time
        // hard_conflict — clear the stale flag optimistically; if it's
        // still actually conflicting, Save Schedule's server-side
        // ScheduleConflictService check catches it regardless.
        if (row.auto_generated_meta?.time?.hard_conflict) {
            row.auto_generated_meta = {
                ...row.auto_generated_meta,
                time: { ...row.auto_generated_meta.time, hard_conflict: false },
            };
        }
    }
};

// Room Recommendation Selector — same instant-refresh pattern as
// onFacultyOverride() above; the override is already persisted
// server-side, this just syncs the review panel's in-memory Room
// block + Overall Score so the Live Score updates with no modal
// close and no re-fetch of the whole panel.
const onRoomOverride = (result, { room, overall_score }) => {
    result.room = room;
    if (overall_score !== undefined && overall_score !== null) {
        result.overall_score = overall_score;
    }

    const row = rows.value.find((r) => r.id === result.section_subject_id);
    if (row) {
        row.room_id = room.id;
        if (row.room) row.room = { ...row.room, id: room.id, room_code: room.name?.split(' — ')[0] ?? row.room.room_code };
        // Same optimistic-clear reasoning as onFacultyOverride above.
        if (row.auto_generated_meta?.time?.hard_conflict) {
            row.auto_generated_meta = {
                ...row.auto_generated_meta,
                time: { ...row.auto_generated_meta.time, hard_conflict: false },
            };
        }
    }
};

// Time Recommendation Selector — same instant-refresh pattern as
// onFacultyOverride()/onRoomOverride() above; the override is already
// persisted server-side, this just syncs the review panel's in-memory
// Time block + Overall Score so the Live Score updates with no modal
// close and no re-fetch of the whole panel.
const onTimeOverride = (result, { time, overall_score }) => {
    result.time = time;
    if (overall_score !== undefined && overall_score !== null) {
        result.overall_score = overall_score;
    }

    const row = rows.value.find((r) => r.id === result.section_subject_id);
    if (row) {
        row.days = [...time.days];
        row.start_time = time.start_time;
        row.end_time = time.end_time;
        // Carry the hard_conflict/override_reason flag onto the row
        // itself (not just the review-panel result object) so
        // tableConflicts — which drives blockingConflictRowIds and
        // therefore the Save Schedule / Accept All & Save button —
        // can see it. A genuine Faculty/Room/Section double-booking
        // picked here must block saving, the same way an in-table
        // Section Conflict already does; see tableConflicts below.
        row.auto_generated_meta = {
            ...(row.auto_generated_meta ?? {}),
            time: {
                ...((row.auto_generated_meta ?? {}).time ?? {}),
                hard_conflict: time.hard_conflict ?? false,
                override_reason: time.override_reason ?? null,
            },
        };
    }
};

// Auto Schedule review panel — whether a generated result currently
// carries a hard Faculty/Room/Section double-booking (set by
// onTimeOverride above from scoreArbitraryTime()'s hard_conflict
// flag). Drives the red card border/background + "Scheduling
// Conflict" tag so a blocking row is obvious at a glance, not just
// buried in the amber Manual Override note under Time.
const resultHasHardConflict = (result) => Boolean(result?.time?.hard_conflict);

// One line per conflicting resource (Faculty/Room/Section), naming
// exactly which existing Subject/Section already holds that slot —
// built from conflict_details (see RecommendationService::
// scoreArbitraryTime()/conflictDetail()). Falls back to the plain
// override_reason sentence if conflict_details wasn't returned
// (e.g. an older cached auto_generated_meta from before this field
// existed).
const resultConflictMessages = (result) => {
    const details = result?.time?.conflict_details;
    if (Array.isArray(details) && details.length) {
        const resourceLabel = { faculty: 'Faculty', room: 'Room', section: 'Section' };
        return details.map((d) => {
            const who = [d.subject_code, d.section_code].filter(Boolean).join(' — ');
            const label = resourceLabel[d.resource] ?? 'Slot';
            return who
                ? `${label} conflict — already scheduled for ${who} at this day/time.`
                : `${label} conflict — already booked at this day/time.`;
        });
    }
    return result?.time?.override_reason ? [result.time.override_reason] : ['This time conflicts with an existing schedule.'];
};

/* ------------------------------------------------------------------ */
/* Add Subject dialog                                                  */
/* ------------------------------------------------------------------ */

const addDialogVisible = ref(false);
const activeTab = ref('curriculum');

const openAddDialog = () => {
    activeTab.value = 'curriculum';
    curriculumForm.curriculum_id = props.curriculums.length === 1 ? props.curriculums[0].id : null;
    curriculumForm.year_level = props.sectionYearLevel;
    curriculumForm.semester = props.sectionSemester;
    curriculumPreviewRows.value = [];
    hasPreviewed.value = false;
    manualForm.reset();
    manualForm.clearErrors();
    addDialogVisible.value = true;
};

const closeAddDialog = () => {
    addDialogVisible.value = false;
    curriculumPreviewRows.value = [];
    hasPreviewed.value = false;
};

/* --- Option 1: Load From Curriculum --- */

const yearLevelOptions = computed(() => Object.values(props.yearLevelMap).map((label) => ({ label, value: label })));
const semesterOptions = computed(() => props.semesterOptions.map((label) => ({ label, value: label })));

const curriculumForm = useForm({
    curriculum_id: null,
    year_level: null,
    semester: null,
});

const curriculumPreviewRows = ref([]);
const hasPreviewed = ref(false);
const previewLoading = ref(false);
const previewError = ref('');

const onPreview = async () => {
    previewError.value = '';

    if (!curriculumForm.curriculum_id || !curriculumForm.year_level || !curriculumForm.semester) {
        previewError.value = 'Select a curriculum, year level, and semester first.';
        return;
    }

    previewLoading.value = true;

    try {
        const { data } = await window.axios.get(
            route('scheduling.section-subjects.curriculum-preview', props.section.id),
            {
                params: {
                    curriculum_id: curriculumForm.curriculum_id,
                    year_level: curriculumForm.year_level,
                    semester: curriculumForm.semester,
                },
            },
        );
        curriculumPreviewRows.value = data.subjects;
        hasPreviewed.value = true;

        if (data.subjects.length === 0) {
            previewError.value = 'No new subjects found for this curriculum, year level, and semester.';
        }
    } catch (error) {
        previewError.value = 'Unable to load subjects for that curriculum, year level, and semester.';
    } finally {
        previewLoading.value = false;
    }
};

const removePreviewRow = (subjectId) => {
    curriculumPreviewRows.value = curriculumPreviewRows.value.filter((subject) => subject.id !== subjectId);
};

const curriculumSaving = ref(false);
const curriculumSaveErrors = ref([]);

const onConfirmCurriculumLoad = () => {
    if (curriculumPreviewRows.value.length === 0) {
        return;
    }

    curriculumSaving.value = true;
    curriculumSaveErrors.value = [];

    router.post(
        route('scheduling.section-subjects.store', props.section.id),
        {
            source: 'Curriculum',
            subject_ids: curriculumPreviewRows.value.map((subject) => subject.id),
        },
        {
            preserveScroll: true,
            onSuccess: () => closeAddDialog(),
            onError: (errors) => {
                curriculumSaveErrors.value = Object.values(errors);
            },
            onFinish: () => {
                curriculumSaving.value = false;
            },
        },
    );
};

/* --- Option 2: Manual Selection --- */

const manualForm = useForm({
    subject_ids: [],
});

const manualSubjectOptions = computed(() => props.availableSubjects);

const onAddManual = () => {
    if (manualForm.subject_ids.length === 0) {
        return;
    }

    router.post(
        route('scheduling.section-subjects.store', props.section.id),
        {
            source: 'Manual',
            subject_ids: manualForm.subject_ids,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeAddDialog(),
            onError: (errors) => {
                manualForm.errors.subject_ids = Object.values(errors).flat().join(' ');
            },
        },
    );
};

/* ------------------------------------------------------------------ */
/* Remove Subject                                                      */
/* ------------------------------------------------------------------ */

const onRemove = (row) => {
    Swal.fire({
        title: 'Remove this subject?',
        text: `${row.subject?.subject_code} will be removed from ${props.section.section_code}. The subject itself will not be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, remove it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.section-subjects.destroy', [props.section.id, row.subject_id]), {
                preserveScroll: true,
            });
        }
    });
};

const sourceSeverity = (source) => (source === 'Curriculum' ? 'info' : 'secondary');
const categorySeverity = (category) => (category === 'Major' ? 'info' : 'secondary');
</script>

<template>
    <Head :title="`${section.section_code} — Subjects`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Section Subjects</span>
        </template>

        <div class="max-w-[100rem] mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.sections')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Sections
                </Link>
            </div>

            <!-- REAL-TIME SCHEDULE CHANGE DETECTION — non-blocking notice
                 shown when polling detects another user changed this
                 Section's schedule. Never auto-dismisses/auto-refreshes;
                 the Registrar's current view and any unsaved edits stay
                 exactly as they are until they choose to refresh. -->
            <div
                v-if="schedulePolling.isStale.value"
                class="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3"
            >
                <div class="flex items-start gap-2.5">
                    <i class="pi pi-exclamation-triangle text-amber-500 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-semibold text-amber-800">Schedule Updated</p>
                        <p class="text-sm text-amber-700">
                            Another user changed this schedule. Your current view may be outdated — refresh before saving.
                        </p>
                    </div>
                </div>
                <Button
                    label="Refresh Schedule"
                    icon="pi pi-refresh"
                    size="small"
                    severity="warn"
                    :loading="refreshingSchedule"
                    @click="refreshSchedule"
                />
            </div>

            <!-- Page Title -->
            <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#1E293B] flex items-center gap-2">
                        {{ sectionLabel(section) }}
                        <Button
                            icon="pi pi-info-circle"
                            text
                            rounded
                            size="small"
                            severity="secondary"
                            class="!p-1.5 !text-slate-400"
                            aria-label="How this page works"
                            title="How this page works"
                            @click="toggleHelp"
                        />
                    </h1>
                    <!-- Section switcher — jump straight to another Section
                         (same Academic Year/Semester, same visibility scope
                         as the Sections list) without going Back to Sections. -->
                    <Select
                        v-if="sectionSwitcherOptions.length > 1"
                        v-model="selectedSectionId"
                        :options="sectionSwitcherOptions"
                        optionLabel="label"
                        optionValue="id"
                        filter
                        placeholder="Switch section"
                        class="mt-2 w-full sm:w-72"
                        size="small"
                        @update:modelValue="onSwitchSection"
                    >
                        <template #value="{ value }">
                            <span v-if="currentSwitcherOption" class="flex items-center gap-2">
                                <span
                                    class="inline-block h-2 w-2 rounded-full shrink-0"
                                    :class="[
                                        sectionStatusDotClass(currentSwitcherOption.status),
                                        currentSwitcherOption.status === 'full' ? 'animate-pulse' : '',
                                    ]"
                                ></span>
                                <span>{{ currentSwitcherOption.label }}</span>
                            </span>
                            <span v-else>{{ value }}</span>
                        </template>
                        <template #option="{ option }">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="inline-block h-2 w-2 rounded-full shrink-0"
                                        :class="[
                                            sectionStatusDotClass(option.status),
                                            option.status === 'full' ? 'animate-pulse' : '',
                                        ]"
                                        :title="{
                                            full: `Fully Scheduled (${option.assignedSubjects}/${option.totalSubjects})`,
                                            partial: `Partially Scheduled (${option.assignedSubjects}/${option.totalSubjects})`,
                                            not_scheduled: 'Not Scheduled',
                                            none: 'No Subjects Yet',
                                        }[option.status]"
                                    ></span>
                                    <span>{{ option.label }}</span>
                                </span>
                                <span v-if="option.major" class="text-xs text-slate-400">{{ option.major }}</span>
                            </div>
                        </template>
                    </Select>
                </div>
                <Popover ref="helpPopover" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
                    <p class="w-80 max-w-[85vw] text-sm text-slate-600 leading-relaxed">
                        Build this section's subject list and assign Faculty, Room, Days, and Time directly in the
                        table, or click "Auto Generate Schedule" to let the recommendation engine propose the best
                        Faculty, Room, and Time for every unscheduled subject. Review the result, then click
                        "Save Schedule" to save everything at once.
                    </p>
                </Popover>
                <div class="flex items-center gap-3 shrink-0">
                    <span v-if="hasUnsavedChanges" class="text-sm text-amber-600 font-medium whitespace-nowrap">
                        <i class="pi pi-circle-fill text-[6px] align-middle mr-1"></i>Unsaved changes
                    </span>
                    <Button
                        v-if="hasAutoGeneratedRows"
                        label="Clear Generated Schedule"
                        icon="pi pi-eraser"
                        severity="secondary"
                        outlined
                        :loading="autoClearing"
                        @click="clearAutoSchedule"
                    />
                    <Button
                        label="⚡ Auto Generate Schedule"
                        icon="pi pi-bolt"
                        severity="help"
                        :loading="autoGenerating"
                        :disabled="rows.length === 0"
                        title="Automatically assign the best Faculty, Room, Day, and Time for every unscheduled subject."
                        @click="runAutoGenerate"
                    />
                    <Button
                        label="Save Schedule"
                        icon="pi pi-save"
                        severity="success"
                        :loading="savingSchedule"
                        :disabled="rows.length === 0 || blockingConflictRowIds.size > 0 || schedulePolling.isStale.value"
                        :title="
                            schedulePolling.isStale.value
                                ? 'Schedule changed. Please refresh before saving.'
                                : blockingConflictRowIds.size > 0
                                  ? 'Resolve the Scheduling Issues above before saving.'
                                  : undefined
                        "
                        @click="saveSchedule"
                    />
                </div>
            </div>

            <!-- Section Information -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm mb-6">
                <template #content>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-6">
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Major</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.major?.name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Curriculum</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.curriculum?.code || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Academic Year</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.academic_year }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Year Level</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.year_level }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Est. Students</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.estimated_students }}</p>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Scheduling Issues panel — real-time, recomputed on every edit -->
            <Card v-if="tableConflicts.length > 0" class="!rounded-2xl border border-red-200 bg-red-50/60 shadow-sm mb-6">
                <template #content>
                    <div class="flex items-center gap-2 mb-3">
                        <i class="pi pi-exclamation-triangle text-red-500"></i>
                        <h2 class="font-semibold text-red-700">Scheduling Issues</h2>
                        <Tag :value="`${tableConflicts.length}`" severity="danger" class="!text-xs" />
                    </div>
                    <ul class="space-y-2">
                        <li
                            v-for="(conflict, index) in tableConflicts"
                            :key="index"
                            class="text-sm bg-white rounded-lg border border-red-100 px-3 py-2 flex items-start gap-2"
                        >
                            <Tag
                                :value="conflict.label"
                                :severity="conflict.type === 'capacity' || conflict.type === 'hours' ? 'warning' : 'danger'"
                                class="!text-xs shrink-0"
                            />
                            <span class="text-slate-600">{{ conflict.detail }}</span>
                        </li>
                    </ul>
                </template>
            </Card>

            <!-- Subjects / Room Grid tab switcher -->
            <div class="mb-4 flex items-center gap-1 border-b border-slate-200">
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="pageTab === 'subjects' ? 'border-blue-500 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="pageTab = 'subjects'"
                >
                    Subjects
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="pageTab === 'room-grid' ? 'border-blue-500 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="pageTab = 'room-grid'"
                >
                    Room Grid
                </button>
            </div>

            <!-- Room Grid tab -->
            <Card v-show="pageTab === 'room-grid'" class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <RoomGrid
                        :section="section"
                        :rows="rows"
                        :active-faculty="activeFaculty"
                        :scheduling-window="schedulingWindow"
                        :is-stale="schedulePolling.isStale.value"
                        :expected-schedule-version="schedulePolling.currentVersion.value"
                        @row-updated="onRoomGridRowUpdated"
                        @schedule-stale="onScheduleStaleFromRoomGrid"
                    />
                </template>
            </Card>

            <!-- Subjects / Scheduling table -->
            <Card v-show="pageTab === 'subjects'" class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    placeholder="Search by code, title or category"
                                    class="w-full !pl-9"
                                />
                            </span>
                        </template>
                        <template #end>
                            <div class="flex items-center gap-2">
                                <Button
                                    icon="pi pi-refresh"
                                    severity="secondary"
                                    outlined
                                    :loading="loading"
                                    @click="onRefresh"
                                    aria-label="Refresh"
                                />
                                <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAddDialog" />
                            </div>
                        </template>
                    </Toolbar>

                    <DataTable
                        :value="rows"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden schedule-table"
                        :rowClass="
                            (row) =>
                                rowIsInConflict(row)
                                    ? '!bg-red-50 conflict-row-clickable'
                                    : rowHasCapacityWarning(row.id)
                                      ? '!bg-amber-50'
                                      : dirtyRowIds.has(row.id)
                                        ? '!bg-amber-50'
                                        : undefined
                        "
                        @row-click="onRowClick"
                        stripedRows
                        responsiveLayout="scroll"
                        scrollable
                        scrollHeight="flex"
                        paginator
                        :rows="10"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No subjects assigned yet.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Subject" to load from the curriculum or select manually.
                                </p>
                                <Button
                                    label="Add Subject"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAddDialog"
                                />
                            </div>
                        </template>

                        <Column style="width: 100%">
                            <template #body="{ data }">
                                <div
                                    class="flex flex-col gap-2.5 py-1.5 px-3 -mx-3 rounded-lg transition-colors"
                                    :class="{ 'unscheduled-row': rowIsUnscheduled(data) }"
                                >
                                    <!-- Line 1: EDP Code / Subject Code / Subject Title / Category / Units / Status / Source / Actions -->
                                    <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5">
                                        <div class="min-w-[7rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">EDP Code</p>
                                            <span v-if="data.edp_code" class="font-mono text-xs font-semibold text-indigo-700">
                                                {{ data.edp_code }}
                                            </span>
                                            <Tag v-else value="Pending" severity="secondary" class="!text-[0.65rem]" />
                                        </div>
                                        <div class="min-w-[7rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Subject Code</p>
                                            <span class="text-xs font-medium text-slate-700">{{ data.subject?.subject_code }}</span>
                                        </div>
                                        <div class="min-w-[12rem] flex-1">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Subject Title</p>
                                            <span class="text-xs text-slate-700">{{ data.subject?.subject_title }}</span>
                                        </div>
                                        <div class="min-w-[7rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Category</p>
                                            <Tag :value="data.subject?.category" :severity="categorySeverity(data.subject?.category)" class="!text-[0.65rem]" />
                                        </div>
                                        <div class="w-10">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Units</p>
                                            <span class="text-xs text-slate-700">{{ data.subject?.units }}</span>
                                        </div>
                                        <div class="min-w-[8rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Status</p>
                                            <div class="flex items-center gap-1 flex-wrap">
                                                <Tag :value="displayStatus(data)" :severity="statusSeverity(displayStatus(data))" class="!text-[0.65rem]" />
                                                <Tag
                                                    v-if="data.is_auto_generated"
                                                    value="⚡ Auto"
                                                    severity="help"
                                                    class="!text-[0.65rem]"
                                                    title="Assigned by Auto Generate Schedule — review and click Save Schedule to keep it, or Clear Generated Schedule to discard it."
                                                />
                                                <i
                                                    v-if="rowIsInConflict(data) || rowHasCapacityWarning(data.id)"
                                                    class="pi pi-exclamation-triangle"
                                                    :class="rowIsInConflict(data) ? 'text-red-500' : 'text-amber-500'"
                                                    :title="rowIsInConflict(data) ? (conflictTooltip(data.id) || 'Conflict — click the row to find the best schedule') : (conflictTooltip(data.id) || 'Unresolved scheduling conflict')"
                                                ></i>
                                                <span
                                                    v-if="rowIsInConflict(data)"
                                                    class="text-[0.65rem] text-red-500 underline decoration-dotted cursor-pointer"
                                                    @click.stop="openRecommendDrawer(data)"
                                                >
                                                    Click to find best schedule
                                                </span>
                                                <span
                                                    v-else-if="rowIsUnscheduled(data)"
                                                    class="text-[0.65rem] text-blue-500 italic"
                                                >
                                                    Not yet scheduled
                                                </span>
                                            </div>
                                        </div>
                                        <div class="min-w-[6rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Source</p>
                                            <Tag :value="data.source" :severity="sourceSeverity(data.source)" class="!text-[0.65rem]" />
                                        </div>
                                        <!-- INTELLIGENT IRREGULAR SECTION SCHEDULING — a merged row
                                             rides along on a Regular section's existing class
                                             (see mergeExclusionIds()/IrregularSectionMergeService);
                                             naming that host Section here, next to Status/Source,
                                             makes it obvious at a glance why this row's
                                             Faculty/Room/Days/Time exactly duplicate another
                                             class's, instead of looking like an unexplained
                                             coincidence. -->
                                        <div v-if="data.is_merged" class="min-w-[8rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400">Merged Into</p>
                                            <Tag
                                                :value="data.merged_into?.section?.section_code ?? 'Regular Section'"
                                                severity="info"
                                                icon="pi pi-sitemap"
                                                class="!text-[0.65rem]"
                                            />
                                        </div>
                                        <div class="ml-auto flex items-center gap-1 self-end">
                                            <Button
                                                icon="pi pi-sparkles"
                                                label="Recommend"
                                                text
                                                size="small"
                                                class="!text-xs"
                                                aria-label="Smart Schedule Recommendation"
                                                @click="openRecommendDrawer(data)"
                                            />
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                severity="danger"
                                                size="small"
                                                aria-label="Remove"
                                                @click="onRemove(data)"
                                            />
                                        </div>
                                    </div>

                                    <!-- Line 2: Faculty / Room / Days / Start Time / End Time -->
                                    <div class="flex flex-wrap items-start gap-3 pt-2 border-t border-slate-100">
                                        <!-- Faculty -->
                                        <div class="flex-1 min-w-[15rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Faculty</p>
                                            <div class="flex items-start gap-1">
                                                <Select
                                                    v-model="data.faculty_id"
                                                    :options="facultyGroupsFor(data)"
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    optionGroupLabel="label"
                                                    optionGroupChildren="items"
                                                    filter
                                                    showClear
                                                    placeholder="Select faculty"
                                                    class="w-full"
                                                    :class="{ 'p-invalid': stateFor(data.id).errors.faculty_id || facultyConflictRowIds.has(data.id), 'unscheduled-field': rowIsUnscheduled(data) }"
                                                    emptyMessage="No active faculty"
                                                    emptyFilterMessage="No matching faculty"
                                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                    @update:modelValue="(v) => onFacultyChange(data, v)"
                                                    @show="fetchRecommendations(data)"
                                                >
                                                    <template #optiongroup="{ option }">
                                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                                                    </template>
                                                    <template #option="{ option }">
                                                        <span>{{ option.label }}</span>
                                                        <Tag v-if="option.confidence" :value="option.confidence" :severity="confidenceSeverity(option.confidence)" class="ml-2 !text-[0.65rem]" />
                                                    </template>
                                                </Select>
                                                <Button
                                                    icon="pi pi-sparkles"
                                                    text
                                                    rounded
                                                    size="small"
                                                    severity="secondary"
                                                    class="!p-1.5 shrink-0"
                                                    aria-label="Suggested faculty"
                                                    title="Suggested faculty"
                                                    @click="toggleFacultySuggestions($event, data)"
                                                />
                                            </div>
                                            <p v-if="stateFor(data.id).errors.faculty_id" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.faculty_id }}
                                            </p>
                                            <p v-else-if="facultyConflictRowIds.has(data.id)" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Faculty already booked this day/time.
                                            </p>
                                        </div>

                                        <!-- Room -->
                                        <div class="flex-1 min-w-[14rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Room</p>
                                            <div class="flex items-start gap-1">
                                                <Select
                                                    v-model="data.room_id"
                                                    :options="roomGroupsFor(data)"
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    optionGroupLabel="label"
                                                    optionGroupChildren="items"
                                                    filter
                                                    showClear
                                                    placeholder="Select room"
                                                    class="w-full"
                                                    :class="{ 'p-invalid': stateFor(data.id).errors.room_id || roomConflictRowIds.has(data.id), 'unscheduled-field': rowIsUnscheduled(data) }"
                                                    emptyMessage="No active rooms"
                                                    emptyFilterMessage="No matching rooms"
                                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                    @update:modelValue="(v) => onRoomChange(data, v)"
                                                    @show="fetchRecommendations(data)"
                                                >
                                                    <template #optiongroup="{ option }">
                                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                                                    </template>
                                                    <template #option="{ option }">
                                                        <span>{{ option.label }}</span>
                                                        <Tag v-if="option.confidence" :value="option.confidence" :severity="confidenceSeverity(option.confidence)" class="ml-2 !text-[0.65rem]" />
                                                    </template>
                                                </Select>
                                                <Button
                                                    icon="pi pi-sparkles"
                                                    text
                                                    rounded
                                                    size="small"
                                                    severity="secondary"
                                                    class="!p-1.5 shrink-0"
                                                    aria-label="Suggested rooms"
                                                    title="Suggested rooms"
                                                    @click="toggleRoomSuggestions($event, data)"
                                                />
                                            </div>
                                            <p v-if="stateFor(data.id).errors.room_id" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.room_id }}
                                            </p>
                                            <p v-else-if="roomConflictRowIds.has(data.id)" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Room already booked this day/time.
                                            </p>
                                            <p v-else-if="rowHasCapacityWarning(data.id)" class="text-amber-600 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Section capacity exceeds this room's capacity.
                                            </p>
                                        </div>

                                        <!-- Days -->
                                        <div class="flex-1 min-w-[12rem]">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Days</p>
                                            <div class="flex items-start gap-1">
                                                <MultiSelect
                                                    v-model="data.days"
                                                    :options="dayOptions"
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    placeholder="Select days"
                                                    :selectionLimit="2"
                                                    class="w-full"
                                                    :class="{ 'p-invalid': stateFor(data.id).errors.days || sectionConflictRowIds.has(data.id), 'unscheduled-field': rowIsUnscheduled(data) }"
                                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                                    @update:modelValue="(v) => onDaysChange(data, v)"
                                                >
                                                    <template #value="{ value, placeholder }">
                                                        <span v-if="!value || value.length === 0" class="text-slate-400">{{ placeholder }}</span>
                                                        <span v-else class="font-medium">{{ formatDays(value) }}</span>
                                                    </template>
                                                    <template #header>
                                                        <div class="flex flex-wrap gap-1 px-3 pt-2 pb-1">
                                                            <button
                                                                v-for="preset in dayPresets"
                                                                :key="preset.label"
                                                                type="button"
                                                                class="text-xs px-2 py-1 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-600"
                                                                @click="applyDayPreset(data, preset)"
                                                            >
                                                                {{ preset.label }}
                                                            </button>
                                                        </div>
                                                    </template>
                                                </MultiSelect>
                                                <Button
                                                    icon="pi pi-sparkles"
                                                    text
                                                    rounded
                                                    size="small"
                                                    severity="secondary"
                                                    class="!p-1.5 shrink-0"
                                                    aria-label="Suggested times"
                                                    title="Suggested times"
                                                    @click="toggleTimeSuggestions($event, data)"
                                                />
                                            </div>
                                            <p v-if="stateFor(data.id).errors.days" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.days }}
                                            </p>
                                            <p v-else-if="sectionConflictRowIds.has(data.id)" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>Overlaps another class in this section.
                                            </p>
                                        </div>

                                        <!-- Start Time -->
                                        <div class="w-36">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">Start Time</p>
                                            <DatePicker
                                                :modelValue="startTimeModel(data)"
                                                timeOnly
                                                hourFormat="12"
                                                showIcon
                                                iconDisplay="input"
                                                placeholder="Start"
                                                class="w-full"
                                                :class="{ 'p-invalid': stateFor(data.id).errors.start_time, 'unscheduled-field': rowIsUnscheduled(data) }"
                                                :pt="{ panel: { class: isDark ? 'dark-scope' : '' } }"
                                                @update:modelValue="(v) => onStartTimeChange(data, v)"
                                            />
                                            <p v-if="stateFor(data.id).errors.start_time" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.start_time }}
                                            </p>
                                        </div>

                                        <!-- End Time -->
                                        <div class="w-36">
                                            <p class="text-[0.65rem] uppercase tracking-wide text-slate-400 mb-1">End Time</p>
                                            <DatePicker
                                                :modelValue="endTimeModel(data)"
                                                timeOnly
                                                hourFormat="12"
                                                showIcon
                                                iconDisplay="input"
                                                placeholder="End"
                                                class="w-full"
                                                :class="{ 'p-invalid': stateFor(data.id).errors.end_time, 'unscheduled-field': rowIsUnscheduled(data) }"
                                                :pt="{ panel: { class: isDark ? 'dark-scope' : '' } }"
                                                @update:modelValue="(v) => onEndTimeChange(data, v)"
                                            />
                                            <p v-if="stateFor(data.id).errors.end_time" class="text-red-500 text-xs mt-1">
                                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.end_time }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Row-level errors — Hours Mismatch / Capacity. Unlike
                                         Faculty/Room/Days/Time, these don't belong to one single
                                         field, so they were previously set into stateFor(id).errors
                                         by the server (batchUpdateSchedule()'s 'hours'/'capacity'
                                         keys) but never actually rendered anywhere — a save could
                                         fail for one of these reasons with the row highlighted and
                                         NO visible explanation at all. Surfaced here as a banner
                                         for the whole row instead. -->
                                    <p v-if="stateFor(data.id).errors.hours" class="text-red-500 text-xs mt-1">
                                        <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.hours }}
                                    </p>
                                    <p v-if="stateFor(data.id).errors.capacity" class="text-red-500 text-xs mt-1">
                                        <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.capacity }}
                                    </p>
                                </div>
                            </template>
                        </Column>

                    </DataTable>

                    <!-- Suggested Time popover (Days column quick-pick) -->
                    <Popover ref="timePopover" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
                        <div class="w-72 max-w-[85vw]">
                            <p class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400 mb-1.5 px-1">
                                Suggested Times<span v-if="timePopoverRow?.subject?.subject_code"> — {{ timePopoverRow.subject.subject_code }}</span>
                            </p>
                            <div v-if="recommendationStateFor(timePopoverRow?.id).loading" class="text-xs text-slate-500 px-1 py-1.5">
                                Finding open slots…
                            </div>
                            <div v-else-if="recommendationStateFor(timePopoverRow?.id).error" class="text-xs text-red-500 px-1 py-1.5">
                                {{ recommendationStateFor(timePopoverRow?.id).error }}
                            </div>
                            <div v-else-if="recommendationStateFor(timePopoverRow?.id).time?.message" class="text-xs text-slate-500 px-1 py-1.5">
                                {{ recommendationStateFor(timePopoverRow?.id).time.message }}
                            </div>
                            <ul v-else class="flex flex-col gap-2 max-h-64 overflow-y-auto pr-1">
                                <li
                                    v-for="(rec, idx) in recommendationStateFor(timePopoverRow?.id).time?.recommendations ?? []"
                                    :key="idx"
                                    class="flex flex-col gap-1 pb-1.5 border-b border-slate-100 last:border-b-0 last:pb-0"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-700 truncate">
                                                {{ formatDays(rec.days) }} · {{ formatTimeRange(rec.start_time, rec.end_time) }}
                                            </p>
                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.6rem]" />
                                                <span class="text-[0.65rem] font-semibold text-slate-500">{{ rec.score }}/{{ rec.score_max }} pts</span>
                                            </div>
                                        </div>
                                        <Button label="Use This" text size="small" class="!text-xs !p-1" @click="applyTimeRecommendationFromPopover(timePopoverRow, rec)" />
                                    </div>
                                    <div class="w-full h-1 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full" :style="{ width: rec.score + '%', backgroundColor: scoreColor(rec.score) }"></div>
                                    </div>
                                    <ul class="flex flex-wrap gap-x-2 gap-y-0.5">
                                        <li
                                            v-for="reason in rec.reasons"
                                            :key="reason.label"
                                            class="text-[0.65rem] flex items-center gap-1"
                                            :class="reason.met ? 'text-emerald-600' : 'text-slate-400'"
                                        >
                                            <i :class="reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle'"></i>{{ reason.label }}
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                            <p class="text-[0.65rem] text-slate-400 mt-1.5 px-1">
                                <i class="pi pi-info-circle mr-1"></i>Suggestions only — nothing is assigned until you click "Use This".
                            </p>
                        </div>
                    </Popover>

                    <!-- Suggested Faculty popover (Faculty column quick-pick) -->
                    <Popover ref="facultyPopover" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
                        <div class="w-72 max-w-[85vw]">
                            <p class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400 mb-1.5 px-1">
                                Suggested Faculty<span v-if="facultyPopoverRow?.subject?.subject_code"> — {{ facultyPopoverRow.subject.subject_code }}</span>
                            </p>
                            <div v-if="recommendationStateFor(facultyPopoverRow?.id).loading" class="text-xs text-slate-500 px-1 py-1.5">
                                Finding the best matches…
                            </div>
                            <div v-else-if="recommendationStateFor(facultyPopoverRow?.id).error" class="text-xs text-red-500 px-1 py-1.5">
                                {{ recommendationStateFor(facultyPopoverRow?.id).error }}
                            </div>
                            <div v-else-if="recommendationStateFor(facultyPopoverRow?.id).faculty?.message" class="text-xs text-slate-500 px-1 py-1.5">
                                {{ recommendationStateFor(facultyPopoverRow?.id).faculty.message }}
                            </div>
                            <ul v-else class="flex flex-col gap-2 max-h-64 overflow-y-auto pr-1">
                                <li
                                    v-for="rec in recommendationStateFor(facultyPopoverRow?.id).faculty?.recommendations ?? []"
                                    :key="rec.id"
                                    class="flex flex-col gap-1 pb-1.5 border-b border-slate-100 last:border-b-0 last:pb-0"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-700 truncate">{{ rec.name }}</p>
                                            <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                                <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.6rem]" />
                                                <span class="text-[0.65rem] font-semibold text-slate-500">{{ rec.score }}/{{ rec.score_max }} pts</span>
                                            </div>
                                        </div>
                                        <Button label="Use This" text size="small" class="!text-xs !p-1" @click="applyFacultyRecommendationFromPopover(facultyPopoverRow, rec)" />
                                    </div>
                                    <div class="w-full h-1 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full" :style="{ width: rec.score + '%', backgroundColor: scoreColor(rec.score) }"></div>
                                    </div>
                                    <ul class="flex flex-wrap gap-x-2 gap-y-0.5">
                                        <li
                                            v-for="reason in rec.reasons"
                                            :key="reason.label"
                                            class="text-[0.65rem] flex items-center gap-1"
                                            :class="reason.met ? 'text-emerald-600' : 'text-slate-400'"
                                        >
                                            <i :class="reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle'"></i>{{ reason.label }}
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                            <p class="text-[0.65rem] text-slate-400 mt-1.5 px-1">
                                <i class="pi pi-info-circle mr-1"></i>Suggestions only — nothing is assigned until you click "Use This".
                            </p>
                        </div>
                    </Popover>

                    <!-- Suggested Room popover (Room column quick-pick) -->
                    <Popover ref="roomPopover" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
                        <div class="w-72 max-w-[85vw]">
                            <p class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400 mb-1.5 px-1">
                                Suggested Rooms<span v-if="roomPopoverRow?.subject?.subject_code"> — {{ roomPopoverRow.subject.subject_code }}</span>
                            </p>
                            <div v-if="recommendationStateFor(roomPopoverRow?.id).loading" class="text-xs text-slate-500 px-1 py-1.5">
                                Finding the best matches…
                            </div>
                            <div v-else-if="recommendationStateFor(roomPopoverRow?.id).error" class="text-xs text-red-500 px-1 py-1.5">
                                {{ recommendationStateFor(roomPopoverRow?.id).error }}
                            </div>
                            <div v-else-if="recommendationStateFor(roomPopoverRow?.id).room?.message" class="text-xs text-slate-500 px-1 py-1.5">
                                {{ recommendationStateFor(roomPopoverRow?.id).room.message }}
                            </div>
                            <ul v-else class="flex flex-col gap-2 max-h-64 overflow-y-auto pr-1">
                                <li
                                    v-for="rec in recommendationStateFor(roomPopoverRow?.id).room?.recommendations ?? []"
                                    :key="rec.id"
                                    class="flex flex-col gap-1 pb-1.5 border-b border-slate-100 last:border-b-0 last:pb-0"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-700 truncate">{{ rec.name }}</p>
                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.6rem]" />
                                                <span class="text-[0.65rem] font-semibold text-slate-500">{{ rec.score }}/{{ rec.score_max }} pts</span>
                                            </div>
                                        </div>
                                        <Button label="Use This" text size="small" class="!text-xs !p-1" @click="applyRoomRecommendationFromPopover(roomPopoverRow, rec)" />
                                    </div>
                                    <div class="w-full h-1 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full" :style="{ width: rec.score + '%', backgroundColor: scoreColor(rec.score) }"></div>
                                    </div>
                                    <ul class="flex flex-wrap gap-x-2 gap-y-0.5">
                                        <li
                                            v-for="reason in rec.reasons"
                                            :key="reason.label"
                                            class="text-[0.65rem] flex items-center gap-1"
                                            :class="reason.met ? 'text-emerald-600' : 'text-slate-400'"
                                        >
                                            <i :class="reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle'"></i>{{ reason.label }}
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                            <p class="text-[0.65rem] text-slate-400 mt-1.5 px-1">
                                <i class="pi pi-info-circle mr-1"></i>Suggestions only — nothing is assigned until you click "Use This".
                            </p>
                        </div>
                    </Popover>
                </template>
            </Card>
        </div>

        <!-- Smart Schedule Recommendation Drawer (Prompt 8.7) -->
        <Drawer
            v-model:visible="recommendDrawerVisible"
            position="right"
            :style="{ width: '38rem' }"
            :breakpoints="{ '960px': '90vw', '640px': '100vw' }"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
        >
            <template #header>
                <div>
                    <p class="text-lg font-semibold text-slate-800">
                        <i class="pi pi-sparkles mr-1.5 text-indigo-500"></i>Smart Schedule Recommendation
                    </p>
                    <p v-if="recommendDrawerRow" class="text-xs text-slate-400 mt-0.5">
                        Analyzed against all available Faculty, Rooms, and Time slots
                    </p>
                </div>
            </template>

            <div v-if="recommendDrawerRow" class="flex flex-col gap-5">
                <!-- Context header: Subject / Section / Major / Academic Year / Semester -->
                <div class="grid grid-cols-2 gap-3 bg-slate-50 rounded-lg p-3 text-sm">
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Subject</p>
                        <p class="font-medium text-slate-800">
                            {{ recommendDrawerRow.subject?.subject_code }} — {{ recommendDrawerRow.subject?.subject_name }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Section</p>
                        <p class="font-medium text-slate-800">{{ sectionLabel(section) }}</p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Major</p>
                        <p class="font-medium text-slate-800">{{ section.major?.name || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Academic Year</p>
                        <p class="font-medium text-slate-800">{{ section.academic_year || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[0.7rem] uppercase tracking-wide text-slate-400">Semester</p>
                        <p class="font-medium text-slate-800">{{ section.semester || '—' }}</p>
                    </div>
                </div>

                <div class="flex justify-end -mt-2">
                    <Button
                        icon="pi pi-refresh"
                        label="Re-analyze"
                        text
                        size="small"
                        :loading="recommendDrawerState.loading"
                        @click="fetchRecommendations(recommendDrawerRow, true)"
                    />
                </div>

                <div v-if="recommendDrawerState.loading" class="text-sm text-slate-500 text-center py-6">
                    <i class="pi pi-spin pi-spinner mr-1.5"></i>Analyzing Faculty, Rooms, and Time slots…
                </div>
                <div v-else-if="recommendDrawerState.error" class="text-sm text-red-500 text-center py-6">
                    {{ recommendDrawerState.error }}
                </div>

                <template v-else>
                    <!-- Combined Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-star-fill mr-1 text-amber-400"></i>Combined Recommendations
                        </p>
                        <p v-if="recommendDrawerState.combined?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.combined.message }}
                        </p>
                        <div v-else class="flex flex-col gap-3">
                            <Card
                                v-for="(combo, idx) in recommendDrawerState.combined?.recommendations ?? []"
                                :key="idx"
                                class="border border-slate-200 shadow-none"
                            >
                                <template #content>
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="text-sm font-semibold text-slate-800">Recommendation #{{ idx + 1 }}</p>
                                            <Tag
                                                v-if="combo.is_sibling_pattern"
                                                :value="`Matches ${combo.pattern_source?.donor_section_code ?? 'sibling section'}`"
                                                severity="info"
                                                icon="pi pi-sparkles"
                                            />
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <Tag :value="combo.confidence" :severity="confidenceSeverity(combo.confidence)" />
                                            <span class="text-xs font-semibold text-slate-500">{{ combo.score }}/{{ combo.score_max }}%</span>
                                        </div>
                                    </div>
                                    <ProgressBar
                                        :value="combo.score"
                                        :showValue="false"
                                        style="height: 6px"
                                        :pt="{ value: { style: { backgroundColor: scoreColor(combo.score) } } }"
                                        class="mb-3"
                                    />
                                    <div class="grid grid-cols-3 gap-2 text-xs mb-3">
                                        <div>
                                            <p class="text-slate-400 uppercase tracking-wide text-[0.65rem]">Faculty</p>
                                            <p class="font-medium text-slate-700">{{ combo.faculty.name }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-400 uppercase tracking-wide text-[0.65rem]">Room</p>
                                            <p class="font-medium text-slate-700">{{ combo.room.name }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-400 uppercase tracking-wide text-[0.65rem]">Schedule</p>
                                            <p class="font-medium text-slate-700">
                                                {{ formatDays(combo.time.days) }} · {{ formatTimeRange(combo.time.start_time, combo.time.end_time) }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <p class="text-[0.7rem] text-emerald-600">
                                            <i class="pi pi-check-circle mr-1"></i>Conflict: {{ combo.conflict || 'None' }}
                                        </p>
                                        <Button
                                            label="Apply"
                                            size="small"
                                            @click="applyCombinedRecommendation(recommendDrawerRow, combo)"
                                        />
                                    </div>
                                </template>
                            </Card>
                        </div>
                    </div>

                    <Divider />

                    <!-- Faculty Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-user mr-1 text-indigo-500"></i>Top Faculty Recommendations
                        </p>
                        <p v-if="recommendDrawerState.faculty?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.faculty.message }}
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="rec in recommendDrawerState.faculty?.recommendations ?? []"
                                :key="rec.id"
                                class="border border-slate-200 rounded-lg p-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate">{{ rec.name }}</p>
                                        <p class="text-[0.7rem] text-slate-400">{{ rec.faculty_category }} · {{ rec.employment_type }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.65rem]" />
                                        <span class="text-xs font-semibold text-slate-500">{{ rec.score }}%</span>
                                    </div>
                                </div>
                                <Tag
                                    v-if="rec.is_sibling_pattern"
                                    value="Matches sibling section"
                                    severity="info"
                                    icon="pi pi-sparkles"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <Tag
                                    v-if="rec.selected_by_college_match"
                                    value="Selected by College Match"
                                    severity="warning"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <Tag
                                    v-if="rec.selected_by_general_education_match"
                                    value="Selected by General Education Match"
                                    severity="warning"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <ProgressBar
                                    :value="rec.score"
                                    :showValue="false"
                                    style="height: 5px"
                                    :pt="{ value: { style: { backgroundColor: scoreColor(rec.score) } } }"
                                    class="my-2"
                                />
                                <p class="text-[0.7rem] text-slate-500 mb-1.5">
                                    Current Load: {{ rec.current_load }} / {{ rec.max_teaching_units }} Units
                                </p>
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 mb-2">
                                    <li
                                        v-for="reason in rec.reasons"
                                        :key="reason.label"
                                        class="text-[0.7rem] flex items-center gap-1"
                                        :class="reason.met ? 'text-emerald-600' : 'text-slate-400'"
                                    >
                                        <i :class="reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle'"></i>{{ reason.label }}
                                    </li>
                                </ul>
                                <div class="flex justify-end">
                                    <Button label="Apply" text size="small" @click="applyFacultyRecommendationFromDrawer(recommendDrawerRow, rec)" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <Divider />

                    <!-- Room Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-building mr-1 text-indigo-500"></i>Top Room Recommendations
                        </p>
                        <p v-if="recommendDrawerState.room?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.room.message }}
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="rec in recommendDrawerState.room?.recommendations ?? []"
                                :key="rec.id"
                                class="border border-slate-200 rounded-lg p-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate">{{ rec.name }}</p>
                                        <p class="text-[0.7rem] text-slate-400">
                                            {{ rec.room_category || rec.room_type }} · Capacity {{ rec.capacity }}
                                            <span v-if="rec.department"> · {{ rec.department }}</span>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.65rem]" />
                                        <span class="text-xs font-semibold text-slate-500">{{ rec.score }}%</span>
                                    </div>
                                </div>
                                <Tag
                                    v-if="rec.is_sibling_pattern"
                                    value="Matches sibling section"
                                    severity="info"
                                    icon="pi pi-sparkles"
                                    class="!text-[0.65rem] mt-1"
                                />
                                <ProgressBar
                                    :value="rec.score"
                                    :showValue="false"
                                    style="height: 5px"
                                    :pt="{ value: { style: { backgroundColor: scoreColor(rec.score) } } }"
                                    class="my-2"
                                />
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 mb-2">
                                    <li
                                        v-for="reason in rec.reasons"
                                        :key="reason.label"
                                        class="text-[0.7rem] flex items-center gap-1"
                                        :class="reason.type === 'warning' ? 'text-amber-600' : (reason.met ? 'text-emerald-600' : 'text-slate-400')"
                                    >
                                        <i :class="reason.type === 'warning' ? 'pi pi-exclamation-triangle' : (reason.met ? 'pi pi-check-circle' : 'pi pi-times-circle')"></i>{{ reason.label }}
                                    </li>
                                </ul>
                                <div class="flex justify-end">
                                    <Button label="Apply" text size="small" @click="applyRoomRecommendationFromDrawer(recommendDrawerRow, rec)" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <Divider />

                    <!-- Time Recommendations -->
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-2">
                            <i class="pi pi-clock mr-1 text-indigo-500"></i>Top Time Recommendations
                        </p>
                        <p v-if="recommendDrawerState.time?.message" class="text-sm text-slate-500">
                            {{ recommendDrawerState.time.message }}
                        </p>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="(rec, idx) in recommendDrawerState.time?.recommendations ?? []"
                                :key="idx"
                                class="border border-slate-200 rounded-lg p-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate">
                                            {{ formatDays(rec.days) }} · {{ formatTimeRange(rec.start_time, rec.end_time) }}
                                        </p>
                                        <p class="text-[0.7rem] text-emerald-600">No conflicts detected</p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Tag :value="rec.confidence" :severity="confidenceSeverity(rec.confidence)" class="!text-[0.65rem]" />
                                        <span class="text-xs font-semibold text-slate-500">{{ rec.score }}%</span>
                                    </div>
                                </div>
                                <ProgressBar
                                    :value="rec.score"
                                    :showValue="false"
                                    style="height: 5px"
                                    :pt="{ value: { style: { backgroundColor: scoreColor(rec.score) } } }"
                                    class="my-2"
                                />
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 mb-2">
                                    <li
                                        v-for="reason in rec.reasons"
                                        :key="reason.label"
                                        class="text-[0.7rem] flex items-center gap-1 text-emerald-600"
                                    >
                                        <i class="pi pi-check-circle"></i>{{ reason.label }}
                                    </li>
                                </ul>
                                <div class="flex justify-end">
                                    <Button label="Apply" text size="small" @click="applyTimeRecommendationFromDrawer(recommendDrawerRow, rec)" />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- SIBLING SECTION PATTERN MATCHING — diagnostic trail for
                         this row, showing every sibling donor considered and why
                         each Day candidate was accepted/rejected. Present even
                         when a sibling match WAS found (top Combined suggestion)
                         so the Registrar can see the full reasoning, not just the
                         winning candidate. -->
                    <template v-if="recommendDrawerState.siblingDiagnostics?.length">
                        <Divider />
                        <details>
                            <summary class="text-sm font-semibold text-slate-700 cursor-pointer select-none">
                                <i class="pi pi-copy mr-1 text-indigo-500"></i>Sibling Section Pattern Matching — details
                            </summary>
                            <div class="mt-2 space-y-2">
                                <div
                                    v-for="(donorTrace, dIdx) in recommendDrawerState.siblingDiagnostics"
                                    :key="dIdx"
                                    class="text-xs bg-slate-50 border border-slate-200 rounded-md p-2"
                                >
                                    <p class="font-medium text-slate-700">
                                        Donor: {{ donorTrace.donor_section }}
                                        <span v-if="donorTrace.faculty || donorTrace.room" class="text-slate-400 font-normal">
                                            — {{ donorTrace.faculty || '—' }}, {{ donorTrace.room || '—' }}
                                        </span>
                                    </p>
                                    <p class="text-slate-500">{{ donorTrace.outcome }}</p>
                                    <ul v-if="donorTrace.days_tried?.length" class="mt-1 space-y-0.5">
                                        <li
                                            v-for="(attempt, aIdx) in donorTrace.days_tried"
                                            :key="aIdx"
                                            class="flex items-start gap-1"
                                            :class="attempt.result === 'rejected' ? 'text-red-500' : (attempt.result === 'accepted' ? 'text-emerald-600' : 'text-slate-400')"
                                        >
                                            <i
                                                :class="attempt.result === 'rejected' ? 'pi pi-times-circle' : (attempt.result === 'accepted' ? 'pi pi-check-circle' : 'pi pi-minus-circle')"
                                                class="mt-0.5"
                                            ></i>
                                            <span>
                                                <span class="font-medium">{{ formatDays(attempt.days) }}</span>
                                                — {{ attempt.reason }}
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </details>
                    </template>
                </template>

                <p class="text-xs text-slate-400 border-t border-slate-100 pt-3">
                    <i class="pi pi-info-circle mr-1"></i>Recommendations are suggestions only. Applying one only fills in the
                    scheduling table below — click <span class="font-medium">Save Schedule</span> to persist it.
                </p>
            </div>
        </Drawer>

        <!-- Add Subject Dialog -->
        <Dialog
            v-model:visible="addDialogVisible"
            modal
            header="Add Subject"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
            @hide="closeAddDialog"
        >
            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="curriculum">Load From Curriculum</Tab>
                    <Tab value="manual">Manual Selection</Tab>
                </TabList>
                <TabPanels>
                    <!-- Option 1: Load From Curriculum -->
                    <TabPanel value="curriculum">
                        <p class="text-sm text-slate-500 mb-3">
                            Curricula are limited to <span class="font-medium text-slate-700">{{ section.major?.name }}</span>.
                            Year Level and Semester are pre-filled from this section but can be changed.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Curriculum</label>
                                <Select
                                    v-model="curriculumForm.curriculum_id"
                                    :options="curriculums"
                                    optionLabel="code"
                                    optionValue="id"
                                    filter
                                    placeholder="Select a curriculum"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                >
                                    <template #option="{ option }">
                                        <span class="font-medium">{{ option.code }}</span>
                                        <span class="text-slate-400"> — {{ option.name }}</span>
                                    </template>
                                </Select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Year Level</label>
                                <Select
                                    v-model="curriculumForm.year_level"
                                    :options="yearLevelOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select year level"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Semester</label>
                                <Select
                                    v-model="curriculumForm.semester"
                                    :options="semesterOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select semester"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
                        </div>

                        <Button
                            label="Preview Subjects"
                            icon="pi pi-eye"
                            severity="secondary"
                            outlined
                            :loading="previewLoading"
                            @click="onPreview"
                        />

                        <small v-if="previewError" class="block text-red-500 mt-2">{{ previewError }}</small>
                        <small v-for="(err, idx) in curriculumSaveErrors" :key="idx" class="block text-red-500 mt-1">
                            {{ err }}
                        </small>

                        <!-- Preview table -->
                        <div v-if="hasPreviewed && curriculumPreviewRows.length > 0" class="mt-4">
                            <p class="text-sm font-medium text-slate-700 mb-2">
                                Preview — remove any subjects you don't want before confirming.
                            </p>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-slate-500">
                                        <tr>
                                            <th class="text-left px-3 py-2 font-medium">Code</th>
                                            <th class="text-left px-3 py-2 font-medium">Title</th>
                                            <th class="text-center px-3 py-2 font-medium">Units</th>
                                            <th class="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr v-for="subject in curriculumPreviewRows" :key="subject.id">
                                            <td class="px-3 py-2 font-medium text-slate-700">{{ subject.subject_code }}</td>
                                            <td class="px-3 py-2 text-slate-600">{{ subject.subject_title }}</td>
                                            <td class="px-3 py-2 text-center text-slate-600">{{ subject.units }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <Button
                                                    icon="pi pi-times"
                                                    text
                                                    rounded
                                                    severity="danger"
                                                    size="small"
                                                    aria-label="Remove from preview"
                                                    @click="removePreviewRow(subject.id)"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button
                                    label="Confirm & Add"
                                    icon="pi pi-check"
                                    severity="success"
                                    :loading="curriculumSaving"
                                    @click="onConfirmCurriculumLoad"
                                />
                            </div>
                        </div>
                    </TabPanel>

                    <!-- Option 2: Manual Selection -->
                    <TabPanel value="manual">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-medium text-slate-700">Search Subject</label>
                            <MultiSelect
                                v-model="manualForm.subject_ids"
                                :options="manualSubjectOptions"
                                optionLabel="subject_code"
                                optionValue="id"
                                filter
                                filterPlaceholder="Search subject code or title"
                                display="chip"
                                placeholder="Select one or multiple subjects"
                                :invalid="!!manualForm.errors.subject_ids"
                                class="w-full"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                            >
                                <template #option="{ option }">
                                    <span class="font-medium">{{ option.subject_code }}</span>
                                    <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                </template>
                            </MultiSelect>
                            <small v-if="manualForm.errors.subject_ids" class="text-red-500">
                                {{ manualForm.errors.subject_ids }}
                            </small>
                        </div>

                        <div class="flex justify-end mt-4">
                            <Button
                                label="Save"
                                icon="pi pi-check"
                                severity="success"
                                :loading="manualForm.processing"
                                :disabled="manualForm.subject_ids.length === 0"
                                @click="onAddManual"
                            />
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="closeAddDialog" />
            </template>
        </Dialog>

        <!-- ⚡ Auto Generate Schedule — review panel -->
        <Dialog
            :visible="autoSummaryVisible"
            @update:visible="onAutoSummaryVisibleChange"
            modal
            header="⚡ Auto Schedule Complete"
            :style="{ width: '860px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
        >
            <!-- Schedule changed while this Auto Schedule review panel was
                 open (spec Section 9). "Accept All & Save" is already
                 disabled for this; this just makes the reason visible
                 inside the modal itself. -->
            <div
                v-if="schedulePolling.isStale.value"
                class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 mb-4 flex items-start gap-2.5"
            >
                <i class="pi pi-exclamation-triangle text-amber-500 mt-0.5"></i>
                <p class="text-sm text-amber-700">
                    Schedule changed while Auto Schedule was being generated. Please refresh before accepting this schedule.
                </p>
            </div>

            <div v-if="autoSummary">
                <div
                    class="rounded-xl border p-4 mb-4 flex items-center gap-3"
                    :class="autoSummary.scheduled === autoSummary.total ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'"
                >
                    <i
                        class="pi text-2xl"
                        :class="autoSummary.scheduled === autoSummary.total ? 'pi-check-circle text-green-600' : 'pi-exclamation-triangle text-amber-600'"
                    ></i>
                    <div>
                        <p class="font-semibold text-slate-800">
                            {{ autoSummary.scheduled }} of {{ autoSummary.total }} subjects scheduled.
                        </p>
                        <p class="text-sm text-slate-600">{{ autoSummary.message }}</p>
                    </div>
                </div>

                <!-- Successfully scheduled subjects -->
                <div v-if="autoSummary.results?.length" class="space-y-3 mb-4">
                    <p class="text-sm font-medium text-slate-700">Generated Assignments</p>
                    <div
                        v-for="result in autoSummary.results"
                        :key="result.section_subject_id"
                        class="border rounded-xl p-4"
                        :class="resultHasHardConflict(result) ? 'border-red-300 bg-red-50' : 'border-slate-200'"
                    >
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <p class="font-semibold text-slate-800">
                                {{ result.subject_code }} <span class="text-slate-400 font-normal">— {{ result.subject_title }}</span>
                            </p>
                            <Tag v-if="resultHasHardConflict(result)" value="Scheduling Conflict" severity="danger" icon="pi pi-exclamation-triangle" class="!text-xs shrink-0" />
                            <Tag v-else-if="result.is_merged" value="Merged" severity="info" class="!text-xs shrink-0" />
                        </div>

                        <!-- Hard conflict banner — names exactly which Section/Subject
                             already occupies the Faculty/Room/Section slot that was
                             just picked, so this row must be fixed before "Accept
                             All & Save" can be used (see blockingConflictRowIds). -->
                        <div
                            v-if="resultHasHardConflict(result)"
                            class="mb-3 rounded-lg border border-red-200 bg-red-100/60 px-3 py-2"
                        >
                            <p v-for="(msg, mIdx) in resultConflictMessages(result)" :key="mIdx" class="text-xs text-red-700">
                                <i class="pi pi-exclamation-triangle mr-1"></i>{{ msg }}
                            </p>
                        </div>

                        <!-- INTELLIGENT IRREGULAR SECTION SCHEDULING — merged subjects
                             don't get their own Faculty/Room/Time selectors; they ride
                             along on the host Regular section's existing class. -->
                        <div v-if="result.is_merged" class="rounded-lg bg-blue-50 border border-blue-100 p-3">
                            <p class="text-sm text-slate-700">
                                <i class="pi pi-sitemap mr-1 text-blue-600"></i>
                                Merged into <span class="font-semibold">{{ result.merged_into_section_code }}</span>
                                — {{ result.faculty?.name || '—' }}, {{ result.room?.name || '—' }},
                                {{ (result.time?.days || []).join('/') }}
                            </p>
                            <div class="mt-2">
                                <Button
                                    label="Merge Recommendation"
                                    icon="pi pi-list"
                                    size="small"
                                    text
                                    @click="openMergeModal(result)"
                                />
                            </div>
                        </div>

                        <div v-else>
                            <!-- SIBLING SECTION PATTERN MATCHING — this is a NEW,
                                 independent ScheduleAssignment for THIS section; only
                                 its Faculty/Room/duration PREFERENCE was based on
                                 another section of the same cohort that already
                                 teaches this exact subject, and the Day was
                                 deliberately changed to avoid conflicting with that
                                 other section's own booking. Nothing was copied or
                                 moved from the donor — see SiblingSectionPatternService.
                                 No badge/explanation shown here on purpose — end users
                                 found "Based on <section>" confusing; result.pattern_source
                                 is still available on the row for anyone who needs it
                                 (e.g. future admin-facing diagnostics). -->

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <!-- Faculty — interactive recommendation selector (Prompt 8.11) -->
                                <div>
                                    <FacultyRecommendationSelector
                                        :section-id="section.id"
                                        :section-subject-id="result.section_subject_id"
                                        :model-value="result.faculty"
                                        @updated="onFacultyOverride(result, $event)"
                                    />
                                </div>

                                <!-- Room — interactive recommendation selector, same click-to-edit/search flow as Faculty -->
                                <div>
                                    <RoomRecommendationSelector
                                        :section-id="section.id"
                                        :section-subject-id="result.section_subject_id"
                                        :model-value="result.room"
                                        @updated="onRoomOverride(result, $event)"
                                    />
                                </div>

                                <!-- Time — interactive recommendation selector, same click-to-edit flow as Faculty/Room -->
                                <div>
                                    <TimeRecommendationSelector
                                        :section-id="section.id"
                                        :section-subject-id="result.section_subject_id"
                                        :model-value="result.time"
                                        @updated="onTimeOverride(result, $event)"
                                    />
                                </div>

                                <div v-if="isIrregularSection" class="sm:col-span-3">
                                    <Button
                                        label="Merge Recommendation"
                                        icon="pi pi-sitemap"
                                        size="small"
                                        text
                                        @click="openMergeModal(result)"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subjects that need manual scheduling -->
                <div v-if="autoSummary.unresolved?.length">
                    <p class="text-sm font-medium text-slate-700 mb-2">Requires Manual Scheduling</p>
                    <div class="space-y-2">
                        <div
                            v-for="item in autoSummary.unresolved"
                            :key="item.section_subject_id"
                            class="border border-amber-200 bg-amber-50 rounded-lg p-3 flex items-start gap-2"
                        >
                            <i class="pi pi-exclamation-triangle text-amber-600 mt-0.5"></i>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-slate-800">
                                    {{ item.subject_code }} — {{ item.subject_title }}
                                </p>
                                <p class="text-xs text-slate-600">{{ item.reason }}</p>
                                <ul v-if="item.reason_details?.length" class="mt-1 space-y-0.5">
                                    <li v-for="(detail, idx) in item.reason_details" :key="idx" class="text-xs text-slate-500">
                                        • {{ detail }}
                                    </li>
                                </ul>

                                <!-- SIBLING SECTION PATTERN MATCHING — diagnostic trail
                                     showing exactly why this row could NOT inherit a
                                     sibling section's Faculty/Room/Duration pattern
                                     (which donor(s) were considered, which Day
                                     candidates were tried, and the exact Section/
                                     Faculty/Room conflict that rejected each one). -->
                                <details v-if="item.sibling_pattern_diagnostics?.length" class="mt-2">
                                    <summary class="text-xs text-amber-700 cursor-pointer select-none">
                                        <i class="pi pi-info-circle mr-1"></i>Why wasn't a sibling section's pattern used?
                                    </summary>
                                    <div class="mt-1.5 space-y-2 pl-1">
                                        <div
                                            v-for="(donorTrace, dIdx) in item.sibling_pattern_diagnostics"
                                            :key="dIdx"
                                            class="text-xs bg-white border border-amber-100 rounded-md p-2"
                                        >
                                            <p class="font-medium text-slate-700">
                                                Donor: {{ donorTrace.donor_section }}
                                                <span v-if="donorTrace.faculty || donorTrace.room" class="text-slate-400 font-normal">
                                                    — {{ donorTrace.faculty || '—' }}, {{ donorTrace.room || '—' }}
                                                </span>
                                            </p>
                                            <p class="text-slate-500">{{ donorTrace.outcome }}</p>
                                            <ul v-if="donorTrace.days_tried?.length" class="mt-1 space-y-0.5">
                                                <li
                                                    v-for="(attempt, aIdx) in donorTrace.days_tried"
                                                    :key="aIdx"
                                                    class="flex items-start gap-1"
                                                    :class="attempt.result === 'rejected' ? 'text-red-500' : (attempt.result === 'accepted' ? 'text-emerald-600' : 'text-slate-400')"
                                                >
                                                    <i
                                                        :class="attempt.result === 'rejected' ? 'pi pi-times-circle' : (attempt.result === 'accepted' ? 'pi pi-check-circle' : 'pi pi-minus-circle')"
                                                        class="mt-0.5"
                                                    ></i>
                                                    <span>
                                                        <span class="font-medium">{{ formatDays(attempt.days) }}</span>
                                                        — {{ attempt.reason }}
                                                    </span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </details>

                                <Button
                                    v-if="isIrregularSection"
                                    label="Merge Recommendation"
                                    icon="pi pi-sitemap"
                                    size="small"
                                    text
                                    class="mt-1 !p-0"
                                    @click="openMergeModal(item)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Clear Generated Schedule" icon="pi pi-eraser" severity="secondary" outlined :loading="autoClearing" @click="clearAutoSchedule" />
                <Button label="Regenerate" icon="pi pi-refresh" severity="warning" outlined :loading="autoGenerating" @click="regenerateAutoSchedule" />
                <Button
                    label="Accept All & Save"
                    icon="pi pi-check"
                    severity="success"
                    :loading="savingSchedule"
                    :disabled="blockingConflictRowIds.size > 0 || schedulePolling.isStale.value"
                    :title="schedulePolling.isStale.value ? 'Schedule changed while Auto Schedule was being generated. Please refresh before accepting.' : undefined"
                    @click="acceptAutoSchedule"
                />
            </template>
        </Dialog>

        <!-- INTELLIGENT IRREGULAR SECTION SCHEDULING — Merge Recommendation modal -->
        <MergeRecommendationModal
            v-model:visible="mergeModalVisible"
            :loading="mergeModalLoading"
            :applying="mergeModalApplying"
            :subject-code="mergeModalResult?.subject_code"
            :subject-title="mergeModalResult?.subject_title"
            :recommendation="mergeModalRecommendation"
            @choose-candidate="chooseMergeCandidate"
            @choose-independent="chooseIndependentSchedule"
        />
    </AppLayout>
</template>

<style scoped>
/* Conflicted rows (red) are click-to-resolve — opens the Smart
   Schedule Recommendation drawer for that row (see openRecommendDrawer). */
.schedule-table :deep(.conflict-row-clickable) {
    cursor: pointer;
}
.schedule-table :deep(.conflict-row-clickable:hover) {
    background-color: rgb(254 226 226) !important; /* red-100 */
}

/* "Not yet scheduled" rows — a Draft row with no Faculty/Room/Days/
   Time assigned at all. Applied to the row's inner content wrapper
   (not the <tr> itself — table rows don't reliably paint left
   borders across browsers due to border-collapse behavior) so the
   dashed accent + tint actually render. Baby-blue so it's clearly
   visible against the neutral table background, distinct from
   conflict/warning rows (red/amber) — an empty row reads as "still
   needs setup", not an error. */
.schedule-table :deep(.unscheduled-row) {
    background-color: #EFF6FF !important; /* blue-50 */
    border-left: 3px dashed #93C5FD !important; /* blue-300 */
}
.schedule-table :deep(.unscheduled-field.p-select),
.schedule-table :deep(.unscheduled-field.p-multiselect),
.schedule-table :deep(.unscheduled-field .p-datepicker-input) {
    border-style: dashed !important;
    border-color: #93C5FD !important; /* blue-300 */
    background-color: #F0F7FF !important;
}

.schedule-table :deep(.p-select),
.schedule-table :deep(.p-multiselect),
.schedule-table :deep(.p-datepicker-input) {
    font-size: 0.8rem;
}

/* Slightly tighter row height/padding + smaller base font so more of
   the table is visible at once and it's easier to scan/read. */
.schedule-table :deep(.p-datatable-thead > tr > th) {
    padding: 0.6rem 0.75rem;
    font-size: 0.8rem;
}
.schedule-table :deep(.p-datatable-tbody > tr > td) {
    padding: 0.5rem 0.75rem;
    font-size: 0.8rem;
}
.schedule-table :deep(.p-select-label),
.schedule-table :deep(.p-multiselect-label),
.schedule-table :deep(.p-inputtext) {
    padding-top: 0.4rem;
    padding-bottom: 0.4rem;
}

/* Dark-mode overrides. Wrapping the page body in the "dark-scope" class
   (added conditionally via isDark) recolors PrimeVue chrome and the
   Tailwind utility classes used throughout the scheduling table, the
   summary card, the Popovers/Drawer, and both Dialogs. Popovers/Drawer/
   Dialog/Select overlay panels are teleported to <body>, so they're
   handled with their own :pt="{ ... : isDark ? 'dark-scope' : '' }"
   props above and matched here via :global(). */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-800) { color: #F1F5F9 !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.hover\:text-slate-700:hover) { color: #E2E8F0 !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.divide-slate-100 > :not([hidden]) ~ :not([hidden])) { border-color: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.05) !important; }
.dark-scope :deep(.bg-green-50) { background-color: rgba(16, 185, 129, 0.12) !important; }
.dark-scope :deep(.border-green-200) { border-color: rgba(16, 185, 129, 0.35) !important; }
.dark-scope :deep(.text-green-600) { color: #34D399 !important; }
.dark-scope :deep(.bg-amber-50) { background-color: rgba(217, 119, 6, 0.14) !important; }
.dark-scope :deep(.border-amber-200) { border-color: rgba(217, 119, 6, 0.35) !important; }
.dark-scope :deep(.unscheduled-row) {
    background-color: rgba(59, 130, 246, 0.10) !important; /* blue-500 @10% */
    border-left-color: rgba(96, 165, 250, 0.55) !important; /* blue-400 */
}
.dark-scope :deep(.unscheduled-field.p-select),
.dark-scope :deep(.unscheduled-field.p-multiselect),
.dark-scope :deep(.unscheduled-field .p-datepicker-input) {
    border-color: rgba(96, 165, 250, 0.45) !important;
    background-color: rgba(59, 130, 246, 0.08) !important;
}
.dark-scope :deep(.text-amber-600) { color: #FBBF24 !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-datepicker-input) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-label.p-placeholder),
.dark-scope :deep(.p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-multiselect-chip .p-chip) { background: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #F1F5F9 !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; background: transparent !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }
.dark-scope :deep(.p-progressbar) { background: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(table.w-full) { color: #F1F5F9 !important; }
.dark-scope :deep(table.w-full thead) { background: rgba(255, 255, 255, 0.06) !important; color: #94A3B8 !important; }
.dark-scope :deep(.border.border-slate-200.rounded-lg) { border-color: rgba(255, 255, 255, 0.12) !important; }

/* Dialogs, Drawer, Popovers, and Select/MultiSelect/DatePicker overlay
   panels are teleported to <body>, so they need :global() rather than
   :deep() to be reached from this scoped style block. */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-slate-500),
:global(.dark-scope.p-dialog p.text-slate-400),
:global(.dark-scope.p-dialog small.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .font-medium.text-slate-700) { color: #E2E8F0 !important; }
:global(.dark-scope.p-dialog .p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-tab) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-tab-active) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-tabpanels) { background: transparent !important; }
:global(.dark-scope.p-dialog .p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }
:global(.dark-scope.p-dialog .p-tabpanel p),
:global(.dark-scope.p-dialog .p-tabpanel span),
:global(.dark-scope.p-dialog .p-tabpanel div) { color: inherit; }
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-700) { color: #E2E8F0 !important; }
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-600) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-500),
:global(.dark-scope.p-dialog .p-tabpanel .text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-tabpanel label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog table.w-full) { color: #F1F5F9 !important; }
:global(.dark-scope.p-dialog table.w-full thead) { background: rgba(255, 255, 255, 0.06) !important; color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .border.border-slate-200.rounded-lg) { border-color: rgba(255, 255, 255, 0.12) !important; }
:global(.dark-scope.p-dialog .divide-slate-100 > *) { border-color: rgba(255, 255, 255, 0.08) !important; }

:global(.dark-scope.p-drawer) { background: #0F1730 !important; color: #F8FAFC !important; border-left: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-drawer .p-drawer-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-drawer .p-drawer-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-drawer .text-slate-800) { color: #F1F5F9 !important; }
:global(.dark-scope.p-drawer .text-slate-700) { color: #CBD5E1 !important; }
:global(.dark-scope.p-drawer .text-slate-500),
:global(.dark-scope.p-drawer .text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-drawer .bg-slate-50) { background-color: rgba(255, 255, 255, 0.06) !important; }

:global(.dark-scope.p-popover) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; }
:global(.dark-scope.p-popover:before),
:global(.dark-scope.p-popover:after) { border-bottom-color: #0F1730 !important; }
:global(.dark-scope.p-popover .text-slate-600) { color: #CBD5E1 !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-option-group),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option-group) { background: rgba(255, 255, 255, 0.04) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.15) !important; }
:global(.p-select-overlay.dark-scope .p-select-empty-message),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-empty-message) { color: #94A3B8 !important; }

:global(.p-datepicker-panel.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-calendar td span) { color: #F1F5F9 !important; }
</style>