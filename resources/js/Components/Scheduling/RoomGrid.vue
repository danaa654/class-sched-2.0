<script setup>
/**
 * Room Grid — room-centric scheduling view for the Section Subjects
 * page (spec Sections 2, 3, 11, 12, 13).
 *
 * This is a VIEW/EDIT surface on top of the exact same SectionSubject
 * rows the "Subjects" tab edits — no separate schedule table, no
 * duplicated write logic. Every drag-and-drop placement goes through
 * the same PATCH `scheduling.section-subjects.schedule` endpoint
 * (SectionSubjectController::updateSchedule()) that the Subjects
 * tab's inline Room/Days/Time cells already use, so Faculty/Section/
 * Room conflict validation, capacity warnings, Practicum exclusion,
 * and RBAC are enforced exactly once, server-side, in one place.
 */
import { ref, computed, onMounted, watch } from 'vue';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Dialog from 'primevue/dialog';
import Tag from 'primevue/tag';
import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';

const props = defineProps({
    section: { type: Object, required: true },
    rows: { type: Array, required: true }, // parent's reactive sectionSubjects rows
    activeFaculty: { type: Array, default: () => [] },
    // REAL-TIME SCHEDULE CHANGE DETECTION — set by the parent's
    // polling composable when another user has changed this
    // Section's schedule since it was loaded. Blocks new drag moves
    // client-side as an early warning; the actual write is still
    // guarded server-side by expectedScheduleVersion below regardless
    // of this flag's freshness.
    isStale: { type: Boolean, default: false },
    // CONCURRENCY HARDENING — the schedule_version this page last
    // knew about, sent as `expected_schedule_version` on every
    // drag-and-drop write so the backend can reject a stale move
    // with HTTP 409 under its locked transaction.
    expectedScheduleVersion: { type: Number, default: null },
    schedulingWindow: {
        type: Object,
        default: () => ({
            start_time: '08:00',
            end_time: '18:00',
            available_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            interval_minutes: 30,
            lunch_start: '12:00',
            lunch_end: '13:00',
        }),
    },
});

// A seed/manual room whose room_name is identical to its room_code
// (e.g. both "Room 306 (Lab 1)") would otherwise render duplicated —
// "Room 306 (Lab 1) — Room 306 (Lab 1)". Only append the name when it
// actually adds information.
const roomLabel = (room) => {
    if (!room) return '';
    const code = (room.room_code || '').trim();
    const name = (room.room_name || '').trim();
    if (!name || name.toLowerCase() === code.toLowerCase()) return code;
    return `${code} — ${name}`;
};

// Left border + tint so Lecture vs Laboratory rooms are visually
// distinguishable at a glance in the sidebar lists, without relying
// on reading the small "Lecture · Cap 40" caption every time.
const roomTypeAccentClass = (room) => {
    if (room?.room_type === 'Laboratory') return 'border-l-4 border-l-purple-400 bg-purple-50/40';
    if (room?.room_type === 'Lecture') return 'border-l-4 border-l-sky-400 bg-sky-50/40';
    return 'border-l-4 border-l-slate-200';
};

const roomTypeDotClass = (room) => {
    if (room?.room_type === 'Laboratory') return 'bg-purple-400';
    if (room?.room_type === 'Lecture') return 'bg-sky-400';
    return 'bg-slate-300';
};

// Left border + tint so Major vs Minor vs General Education subjects
// are visually distinguishable in the Unscheduled Subjects list, same
// convention as roomTypeAccentClass() above.
const subjectCategoryAccentClass = (row) => {
    const category = row?.subject?.category;
    if (category === 'Major') return 'border-l-4 border-l-emerald-400 bg-emerald-50/40';
    if (category === 'Minor' || category === 'General Education') return 'border-l-4 border-l-amber-400 bg-amber-50/40';
    return 'border-l-4 border-l-slate-200';
};

const subjectCategoryDotClass = (row) => {
    const category = row?.subject?.category;
    if (category === 'Major') return 'bg-emerald-400';
    if (category === 'Minor' || category === 'General Education') return 'bg-amber-400';
    return 'bg-slate-300';
};

const emit = defineEmits(['row-updated', 'schedule-stale']);

const toast = useToast();

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

/* ------------------------------------------------------------------ */
/* Left sidebar — Room search / selection (spec Section 2 & 11)        */
/* ------------------------------------------------------------------ */

const recommendedRooms = ref([]);
const searchResults = ref([]);
const roomSearch = ref('');
const roomsLoading = ref(false);
let roomSearchDebounce = null;

const loadRooms = async (search = '') => {
    roomsLoading.value = true;
    try {
        const url = new URL(route('scheduling.section-subjects.rooms', props.section.id));
        if (search) url.searchParams.set('search', search);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        recommendedRooms.value = data.recommended ?? [];
        searchResults.value = data.search_results ?? [];
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not load rooms.', life: 5000 });
    } finally {
        roomsLoading.value = false;
    }
};

watch(roomSearch, () => {
    clearTimeout(roomSearchDebounce);
    roomSearchDebounce = setTimeout(() => loadRooms(roomSearch.value), 300);
});

onMounted(() => loadRooms());

/* ------------------------------------------------------------------ */
/* Selected room + its weekly timetable (spec Section 3 & 13)          */
/* ------------------------------------------------------------------ */

const selectedRoomBasic = ref(null); // from the sidebar list (id/code/name/type/capacity)
const selectedRoomDetail = ref(null); // authoritative detail from roomSchedule()
const assignments = ref([]);
const gridLoading = ref(false);

const selectedRoom = computed(() => selectedRoomDetail.value ?? selectedRoomBasic.value);

const isManualOverride = computed(() => {
    if (!selectedRoomBasic.value) return false;
    return !!selectedRoomBasic.value.is_outside_department;
});

const loadRoomSchedule = async (room) => {
    if (!room) return;
    gridLoading.value = true;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.room-schedule', [props.section.id, room.id]),
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not load this room\'s schedule.');
        selectedRoomDetail.value = data.room;
        assignments.value = data.assignments ?? [];
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not load this room\'s schedule.', life: 5000 });
    } finally {
        gridLoading.value = false;
    }
};

const selectRoom = (room) => {
    selectedRoomBasic.value = room;
    selectedRoomDetail.value = null;
    loadRoomSchedule(room);
};

/* ------------------------------------------------------------------ */
/* Time rows — derived from the Active School Year's Scheduling        */
/* Window, hourly granularity, matching the boundary the Subjects      */
/* tab's own Day & Time editor already enforces server-side.           */
/* ------------------------------------------------------------------ */

const days = computed(() => props.schedulingWindow.available_days?.length ? props.schedulingWindow.available_days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);
const dayLabels = { Mon: 'Mon', Tue: 'Tue', Wed: 'Wed', Thu: 'Thu', Fri: 'Fri', Sat: 'Sat', Sun: 'Sun' };

const toMinutes = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + (m || 0);
};
const toHHMM = (minutes) => {
    const h = Math.floor(minutes / 60) % 24;
    const m = minutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};
const formatHourLabel = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
};

const intervalMinutes = computed(() => props.schedulingWindow.interval_minutes || 30);

// A row "is lunch" if its slot overlaps the fixed Lunch Break window
// (never editable — see SchoolYear's docblock) — same overlap rule
// the Auto Schedule AI itself refuses to cross.
const isLunchRow = (hour) => {
    const lunchStart = toMinutes(props.schedulingWindow.lunch_start || '12:00');
    const lunchEnd = toMinutes(props.schedulingWindow.lunch_end || '13:00');
    const slotStart = toMinutes(hour);
    const slotEnd = slotStart + intervalMinutes.value;
    return slotStart < lunchEnd && slotEnd > lunchStart;
};

// "8:00 AM" -> "8:00 AM – 8:30 AM", using the grid's own interval so a
// 15/30/60-min Time Interval setting always produces the right range.
const formatSlotRange = (hhmm) => {
    const end = toHHMM(toMinutes(hhmm) + intervalMinutes.value);
    return `${formatHourLabel(hhmm)} – ${formatHourLabel(end)}`;
};

// The Lunch Break window is fixed and never scheduled into (see
// isLunchRow above) — visually it's rendered as ONE merged row instead
// of one row per grid interval (e.g. one "12:00 PM – 1:00 PM" row
// instead of two stacked "12:00–12:30" / "12:30–1:00" rows), so it
// doesn't eat extra vertical space the Registrar has to scroll/drag
// past. This is purely a rendering merge — hourRows itself, and every
// index-based calculation in placedBlocks/onDrop/openEditModal, still
// treats lunch as ordinary step-sized rows underneath.
const lunchRowIndices = computed(() => {
    const indices = [];
    hourRows.value.forEach((hour, idx) => {
        if (isLunchRow(hour)) indices.push(idx);
    });
    return indices;
});
const isFirstLunchRow = (rowIndex) => lunchRowIndices.value[0] === rowIndex;
const lunchSpan = computed(() => lunchRowIndices.value.length || 1);
const lunchRangeLabel = computed(() => {
    const start = props.schedulingWindow.lunch_start || '12:00';
    const end = props.schedulingWindow.lunch_end || '13:00';
    return `${formatHourLabel(start)} – ${formatHourLabel(end)}`;
});

const hourRows = computed(() => {
    const start = toMinutes(props.schedulingWindow.start_time || '08:00');
    const end = toMinutes(props.schedulingWindow.end_time || '18:00');
    const step = intervalMinutes.value;
    const rows = [];
    for (let t = start; t < end; t += step) rows.push(toHHMM(t));
    return rows.length ? rows : ['08:00', '08:30', '09:00', '09:30', '10:00'];
});

/* Blocks placed on the grid, one entry per (day, assignment) —      */
/* THEN merged blocks (multiple SectionSubject rows that share the    */
/* exact same Day/Start/End — see IrregularSectionMergeService, which */
/* copies the host's Room/Days/Time verbatim onto every merged row)   */
/* are collapsed into ONE visual block instead of stacking invisibly  */
/* on top of each other. Two DIFFERENT (non-merged) sections can      */
/* never legitimately land on identical Room+Day+Time — that's a Room */
/* conflict ScheduleConflictService already blocks — so sharing a     */
/* slot is itself the signal that a merge produced it.                */
const placedBlocks = computed(() => {
    const raw = [];
    assignments.value.forEach((a) => {
        const dayTokens = (a.days || '').split(',').filter(Boolean);
        const startMin = a.start_time ? toMinutes(a.start_time) : null;
        const endMin = a.end_time ? toMinutes(a.end_time) : null;
        if (startMin === null || endMin === null) return;

        const gridStart = toMinutes(hourRows.value[0]);
        const step = intervalMinutes.value;
        const startIndex = Math.max(0, Math.round((startMin - gridStart) / step));
        const span = Math.max(1, Math.round((endMin - startMin) / step));

        dayTokens.forEach((day) => {
            if (!days.value.includes(day)) return;
            raw.push({ ...a, day, startIndex, span });
        });
    });

    // Group by (day, startIndex, span) — everything in a group is the
    // same class session shared across sections via a merge.
    const groups = new Map();
    raw.forEach((b) => {
        const key = `${b.day}-${b.startIndex}-${b.span}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(b);
    });

    return Array.from(groups.values()).map((members) => {
        if (members.length === 1) return members[0];

        // Which row does editing/dragging THIS block act on?
        // - If the current section is one of the merged members, always
        //   use ITS OWN row — never the host's, even though the host is
        //   preferred elsewhere (see below). Editing/moving must change
        //   the current section's own booking, not silently rewrite a
        //   different section's class out from under it.
        // - Otherwise (every member belongs to some other section — this
        //   block is only relevant here as a drop/merge target) prefer
        //   the true host (Regular section) row, since a merged
        //   Irregular row can never itself be a merge host (see
        //   findHostCandidates) — anchoring on it would make a later
        //   merge-drop silently fail to match a candidate.
        const own = members.find((m) => m.is_current_section);
        const host = own ?? members.find((m) => m.section_type === 'Regular') ?? members[0];

        const sectionCodes = [...new Set(members.map((m) => m.section_code).filter(Boolean))];

        return {
            ...host,
            section_code: sectionCodes.join(' / '),
            is_current_section: !!own,
            // A merged block is only draggable/editable here if the row
            // we're actually going to act on (host) is itself
            // authorized — never because SOME member happens to be.
            can_edit: !!host.can_edit,
            merged_member_section_subject_ids: members.map((m) => m.section_subject_id),
            // Full per-member detail — powers the "Move both sections
            // (keep merged) / Move only <section>" choice offered when
            // dragging a merged block (see onDrop()). Each member keeps
            // its own section_subject_id/section_id/section_code/
            // can_edit so the move can be scoped/authorized correctly
            // per row, never assumed from the collapsed host alone.
            merge_members: members.map((m) => ({
                section_subject_id: m.section_subject_id,
                section_id: m.section_id,
                section_code: m.section_code,
                is_current_section: !!m.is_current_section,
                can_edit: !!m.can_edit,
            })),
        };
    });
});

const blockAt = (day, rowIndex) => placedBlocks.value.find((b) => b.day === day && b.startIndex === rowIndex);
const isCovered = (day, rowIndex) => placedBlocks.value.some((b) => b.day === day && rowIndex > b.startIndex && rowIndex < b.startIndex + b.span);

// CONFLICT DETECTION — the Room Grid used to just draw whatever the
// backend returned and trust the comment above ("a Room conflict
// ScheduleConflictService already blocks") that two different
// Sections could never legitimately overlap here. That trust wasn't
// backed by every write path (see overrideFaculty()/overrideRoom() in
// SectionSubjectController — now fixed, but old bad rows, or any
// future gap, would otherwise render here with no visual sign
// anything was wrong). This does its own overlap check independent of
// the backend: any two DIFFERENT, non-merged blocks on the same day
// whose [startIndex, startIndex+span) ranges overlap are flagged,
// regardless of why they ended up that way.
const overlaps = (a, b) => a.startIndex < b.startIndex + b.span && b.startIndex < a.startIndex + a.span;

const conflictedBlockIds = computed(() => {
    const flagged = new Set();
    const list = placedBlocks.value;
    for (let i = 0; i < list.length; i++) {
        for (let j = i + 1; j < list.length; j++) {
            const a = list[i];
            const b = list[j];
            if (a.day !== b.day) continue;
            if (a.section_subject_id === b.section_subject_id) continue;
            // A legitimate merge already collapsed same-slot rows into
            // one block above (they share a key), so anything left as
            // two SEPARATE blocks overlapping in time is a genuine
            // double-booking, not a merge.
            if (!overlaps(a, b)) continue;
            flagged.add(a.section_subject_id);
            flagged.add(b.section_subject_id);
        }
    }
    return flagged;
});

const blockHasConflict = (block) => !!block && conflictedBlockIds.value.has(block.section_subject_id);

// Four visual states for a placed block, per spec:
// - "current": this Section's own booking — normal blue, click to edit.
// - "authorized": belongs to a DIFFERENT Section, but that Section is
//   within the logged-in user's authorized scheduling scope
//   (can_edit, computed server-side from College/Department scope —
//   see roomSchedule()) — still draggable ("OTHER SECTION"), never
//   forcing the user to switch to that Section first.
// - "finalized": the schedule's Section has been finalized (locked)
//   — visible, never draggable/editable, regardless of scheduling
//   scope. Distinct color from "locked" (out-of-scope) so a Dean
//   can tell "I can't touch this because it's someone else's
//   college" apart from "I can't touch this because it's done."
// - "locked": the schedule's Section is OUTSIDE the user's authorized
//   scheduling scope — visible (Room Grid stays room-centric), never
//   draggable/editable.
const blockAuthState = (block) => {
    if (!block) return 'locked';
    if (block.is_finalized) return 'finalized';
    if (block.is_current_section) return 'current';
    return block.can_edit ? 'authorized' : 'locked';
};

const blockClass = (block) => {
    if (blockHasConflict(block)) return 'bg-red-50 border-2 border-red-500 text-red-700 cursor-grab hover:border-red-600';
    const state = blockAuthState(block);
    if (state === 'current') return 'bg-blue-50 border border-blue-200 text-blue-700 cursor-grab hover:border-blue-400';
    if (state === 'authorized') return 'bg-emerald-50 border border-emerald-300 border-dashed text-emerald-700 cursor-grab hover:border-emerald-500';
    if (state === 'finalized') return 'bg-amber-50 border border-amber-300 text-amber-800 cursor-not-allowed';
    return 'bg-slate-100 border border-slate-300 text-slate-500 cursor-not-allowed';
};

const blockTitle = (block) => {
    if (blockHasConflict(block)) {
        return `⚠ Conflict: overlaps another class in this room/time — ${block.section_code} ${block.subject_code}. This needs to be rescheduled.`;
    }
    const state = blockAuthState(block);
    if (state === 'current') return 'Click to edit · Drag to move';
    if (state === 'authorized') return `Belongs to ${block.section_code} — within your authorized scheduling scope. Drag to move.`;
    if (state === 'finalized') return `${block.section_code}'s schedule is finalized — an Admin/Registrar must unlock it before this can be edited.`;
    return 'This schedule is outside your scheduling scope.';
};

/* ------------------------------------------------------------------ */
/* Unscheduled subjects for the CURRENT section only (spec: "only      */
/* show BSIT-4A subjects" when viewing BSIT-4A). Practicum/OJT is      */
/* excluded — those are never room-scheduled (spec Section 12).        */
/* ------------------------------------------------------------------ */

const needsRoomPlacement = (row) => {
    if (row.subject?.subject_type === 'practicum') return false;
    return !row.room_id || !row.days || row.days.length === 0 || !row.start_time || !row.end_time;
};

const unscheduledSubjects = computed(() => props.rows.filter(needsRoomPlacement));

/* ------------------------------------------------------------------ */
/* Drag & Drop — writes go through the same updateSchedule() endpoint  */
/* the Subjects tab uses. No optimistic local mutation of the grid;    */
/* we re-fetch the room's schedule on success so it always reflects    */
/* the server's own conflict-checked result.                           */
/*                                                                      */
/* Dropping a NEW (unscheduled) subject opens the Assign modal so the  */
/* Registrar sets Hours/Week, Meetings/Week, and Faculty before it's   */
/* ever saved — a single 1-hour guess was the wrong default for e.g.   */
/* a 5-hr/week Capstone subject. Moving an EXISTING block just         */
/* relocates it (same Faculty, same total duration) with no modal.     */
/* ------------------------------------------------------------------ */

const draggingMode = ref(null); // 'new' | 'move'
const draggingRow = ref(null); // full SectionSubject row, for 'new'
const draggingSubjectId = ref(null); // section_subject_id, for 'move'
const draggingDuration = ref(60); // minutes, for 'move'
const draggingBlock = ref(null); // full block being moved, for 'move' — powers the cross-section confirmation dialog
const savingCell = ref(null); // "day-rowIndex" while a write is in flight

const onDragStartNew = (row) => {
    draggingMode.value = 'new';
    draggingRow.value = row;
};

const onDragStartBlock = (block) => {
    // AUTHORIZATION IS PER-ASSIGNMENT, NOT PER-CURRENTLY-VIEWED-SECTION
    // — a block can belong to a different Section than the one this
    // Room Grid is open for and still be draggable, as long as the
    // logged-in user is authorized for THAT block's own Section
    // (block.can_edit, computed server-side in roomSchedule()). Never
    // gate on is_current_section alone.
    if (!block.can_edit) return;
    // BLOCK STALE SAVES EARLY — don't let a new drag even start once
    // polling has flagged this Section's schedule as changed
    // elsewhere; the Registrar should refresh first. Existing
    // in-progress interactions are never interrupted by this flag —
    // it's only checked at the moment a NEW drag begins.
    if (props.isStale) {
        toast.add({
            severity: 'warn',
            summary: 'Schedule changed',
            detail: 'Another user changed this schedule. Refresh before moving anything.',
            life: 5000,
        });
        return;
    }
    draggingMode.value = 'move';
    draggingSubjectId.value = block.section_subject_id;
    draggingDuration.value = (toMinutes(block.end_time) - toMinutes(block.start_time)) || 60;
    draggingBlock.value = block; // full block, for the "moving another section's class" confirmation
};

// Backend errors that are WARNINGS, not hard conflicts — the
// Registrar can explicitly confirm and save anyway (see
// performScheduleAssignmentUpdate()'s Capacity/Hours checks). Any
// OTHER error key (room_id/faculty_id/days — real Room/Faculty/
// Section/Time conflicts from ScheduleConflictService) is a hard
// block and must never be offered a "confirm anyway" retry.
const CONFIRMABLE_WARNING_KEYS = { capacity: 'capacity_confirmed', hours: 'hours_confirmed' };

const writeSchedule = async (subjectId, payload, { successMessage, crossSection = false, silent = false } = {}) => {
    try {
        // Moving a block that belongs to a DIFFERENT Section than the
        // one currently open goes through the dedicated Room Grid move
        // endpoint, which authorizes against THAT block's own Section
        // (see moveRoomGridAssignment()'s docblock) rather than the
        // currently-viewed {section}. Everything else (editing/placing
        // the current section's own subjects) keeps using the existing
        // per-Section endpoint unchanged.
        const url = crossSection
            ? route('scheduling.room-grid.move', subjectId)
            : route('scheduling.section-subjects.schedule', [props.section.id, subjectId]);

        const response = await fetch(
            url,
            {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ ...payload, expected_schedule_version: props.expectedScheduleVersion }),
            },
        );
        const data = await response.json();

        if (response.status === 409 && data.code === 'SCHEDULE_VERSION_CONFLICT') {
            // SAVE CONFLICT RESPONSE — another user's change already
            // committed since this page's version baseline. Do not
            // silently move/overwrite anything; tell the parent so it
            // can mark the whole page stale, and resync this grid to
            // whatever the server actually has now.
            emit('schedule-stale', data.current_version ?? null);
            toast.add({
                severity: 'error',
                summary: 'Save prevented',
                detail: 'Your schedule is outdated because another user made a change. Please refresh and try again.',
                life: 7000,
            });
            await loadRoomSchedule(selectedRoom.value);
            return false;
        }

        if (!response.ok) {
            const errorKeys = data.errors ? Object.keys(data.errors) : [];
            const isConfirmable = errorKeys.length > 0 && errorKeys.every((k) => k in CONFIRMABLE_WARNING_KEYS);

            // Capacity/Hours mismatches are flagged-not-blocked
            // everywhere else in the app (Subjects tab spreadsheet,
            // Assign modal) — the Room Grid drag/drop must offer the
            // same "Confirm to save anyway" instead of just dead-ending
            // on a permanent error toast.
            if (isConfirmable) {
                const detail = Object.values(data.errors).join(' ');
                const result = await Swal.fire({
                    icon: 'warning',
                    title: 'Conflict',
                    text: detail,
                    showCancelButton: true,
                    confirmButtonText: 'Confirm & Save',
                    cancelButtonText: 'Cancel',
                    customClass: { container: 'roomgrid-swal-on-top' },
                });

                if (!result.isConfirmed) return false;

                const confirmedPayload = { ...payload };
                errorKeys.forEach((key) => {
                    confirmedPayload[CONFIRMABLE_WARNING_KEYS[key]] = true;
                });

                return writeSchedule(subjectId, confirmedPayload, { successMessage, crossSection, silent });
            }

            const detail = data.errors
                ? Object.values(data.errors).join(' ')
                : (data.message ?? 'Could not save — check for a Room, Faculty, or Section conflict.');
            toast.add({ severity: 'error', summary: 'Conflict', detail, life: 7000 });
            // Reload so a losing drag/drop visually snaps back to
            // wherever the block ACTUALLY is server-side — critical
            // under concurrency: another user may have just taken this
            // slot, and the grid must show their booking, not silently
            // leave the failed drag's optimistic position on screen.
            await loadRoomSchedule(selectedRoom.value);
            return false;
        }

        if (!silent) {
            toast.add({ severity: 'success', summary: 'Scheduled', detail: successMessage ?? data.message ?? 'Schedule updated.', life: 3000 });
        }
        emit('row-updated', data.sectionSubject);
        await loadRoomSchedule(selectedRoom.value);
        return true;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Could not save this placement.', life: 5000 });
        return false;
    }
};

const onDrop = async (day, rowIndex) => {
    const mode = draggingMode.value;
    draggingMode.value = null;
    if (!selectedRoom.value) return;

    if (mode === 'move') {
        const subjectId = draggingSubjectId.value;
        const block = draggingBlock.value;
        draggingSubjectId.value = null;
        draggingBlock.value = null;
        if (!subjectId) return;

        const startTime = hourRows.value[rowIndex];
        const endTime = toHHMM(toMinutes(startTime) + draggingDuration.value);

        // No-op drop (same room/day/time it already occupies) — skip
        // the round trip and, for a cross-section block, skip the
        // confirmation dialog entirely.
        if (
            block
            && block.day === day
            && block.startIndex === rowIndex
        ) {
            return;
        }

        // MOVE-TO-MERGE — dragging an EXISTING Irregular-section block
        // on top of a DIFFERENT block teaching the exact same Subject
        // is the same "join that class instead of double-booking"
        // intent as dropping an unscheduled subject onto an occupied
        // slot (see attemptMergeDrop below for the 'new' case). Without
        // this, dropping here would just try to move the Irregular
        // section's OWN Faculty/Room/Time onto the target's slot and
        // collide with whoever's already teaching there (Faculty
        // Conflict) — even though what the user actually wants is to
        // drop their own separate booking and ride along on the
        // existing class instead. Only offered when: the block being
        // dragged is NOT itself already a merged block (nothing to ride
        // along on if it's the host of its own merge already), its
        // OWNING section is Irregular (mirrors findHostCandidates()'s
        // one-directional rule — a Regular section's class is always
        // the host, never the guest), and the target slot is a
        // DIFFERENT, already-occupied block for the same Subject.
        const dropTarget = blockAt(day, rowIndex);
        if (
            block?.section_type === 'Irregular'
            && (block.merge_members?.length ?? 0) <= 1
            && dropTarget
            && dropTarget.section_subject_id !== block.section_subject_id
            && dropTarget.subject_code === block.subject_code
        ) {
            await attemptMergeDropExisting(block, dropTarget);
            return;
        }

        // MERGED BLOCK — offer a choice instead of silently moving only
        // the collapsed "host" row and leaving its merge partner(s)
        // behind at the old slot (that mismatch is what used to happen
        // here). block.merge_members carries every underlying
        // SectionSubject row this visual block represents.
        const mergeMembers = block?.merge_members ?? [];
        let moveTargets;

        if (mergeMembers.length > 1) {
            const editableMembers = mergeMembers.filter((m) => m.can_edit);
            if (editableMembers.length === 0) return;

            const allEditable = editableMembers.length === mergeMembers.length;
            const inputOptions = {};
            if (allEditable) inputOptions.both = 'Move both sections (keep merged)';
            editableMembers.forEach((m) => {
                inputOptions[`only-${m.section_subject_id}`] = `Move only ${m.section_code}`;
            });

            const choice = await Swal.fire({
                icon: 'question',
                title: 'Move Merged Schedule',
                html: `
                    <div class="text-left text-sm space-y-1">
                        <div><strong>${block.subject_code ?? 'This subject'}</strong> — ${block.section_code}</div>
                        <div class="text-slate-500">This class is shared across sections via a merge. Move both together, or only one?</div>
                    </div>
                `,
                input: 'radio',
                inputOptions,
                inputValue: allEditable ? 'both' : Object.keys(inputOptions)[0],
                showCancelButton: true,
                confirmButtonText: 'Continue',
                cancelButtonText: 'Cancel',
                customClass: { container: 'roomgrid-swal-on-top' },
                inputValidator: (value) => (!value ? 'Choose an option to continue.' : undefined),
            });
            if (!choice.isConfirmed || !choice.value) return;

            moveTargets = choice.value === 'both'
                ? editableMembers
                : editableMembers.filter((m) => `only-${m.section_subject_id}` === choice.value);
        } else {
            moveTargets = [{
                section_subject_id: subjectId,
                section_id: block?.section_id,
                section_code: block?.section_code,
                is_current_section: block?.is_current_section,
            }];
        }

        // A "move only <one section>" pick out of a merged group is the
        // only case that needs to drop that row's merge link — see
        // clear_merge_link's docblock. Moving the whole merged group
        // together keeps them linked; a lone, never-merged block was
        // never linked to begin with.
        const clearMergeLink = mergeMembers.length > 1 && moveTargets.length === 1 && moveTargets.length < mergeMembers.length;

        const anyCrossSection = moveTargets.some((t) => t.section_id !== props.section.id);

        // For a move involving a schedule belonging to ANOTHER Section
        // (spec: "Move Schedule Assignment?" confirmation) — a direct
        // drag/drop is only "optional confirmation" for the CURRENT
        // section's own blocks, which the existing UI already treats
        // as an immediate move with no dialog.
        if (anyCrossSection) {
            const oldDayLabel = dayLabels[block.day] ?? block.day;
            const newDayLabel = dayLabels[day] ?? day;
            const otherSectionCodes = [...new Set(
                moveTargets.filter((t) => t.section_id !== props.section.id).map((t) => t.section_code),
            )].join(', ');
            const result = await Swal.fire({
                icon: 'question',
                title: 'Move Schedule Assignment?',
                html: `
                    <div class="text-left text-sm space-y-2">
                        <div><strong>${block.subject_code ?? 'This subject'}</strong> — ${block.section_code}</div>
                        <div class="text-slate-500">
                            <div>Current: ${roomLabel(selectedRoom.value)}, ${oldDayLabel} ${formatHourLabel(block.start_time)}–${formatHourLabel(block.end_time)}</div>
                            <div>New: ${roomLabel(selectedRoom.value)}, ${newDayLabel} ${formatHourLabel(startTime)}–${formatHourLabel(endTime)}</div>
                        </div>
                        <div class="pt-1">This schedule belongs to another section within your authorized scheduling scope. Moving it will modify <strong>${otherSectionCodes}</strong>'s schedule.</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Move & Save',
                cancelButtonText: 'Cancel',
                customClass: { container: 'roomgrid-swal-on-top' },
            });
            if (!result.isConfirmed) return;
        }

        savingCell.value = `${day}-${rowIndex}`;
        let allOk = true;
        for (const target of moveTargets) {
            const targetCrossSection = target.section_id !== props.section.id;
            // eslint-disable-next-line no-await-in-loop
            const rowOk = await writeSchedule(
                target.section_subject_id,
                {
                    room_id: selectedRoom.value.id,
                    days: [day],
                    start_time: startTime,
                    end_time: endTime,
                    // Backend independently re-derives same-section vs
                    // cross-section from these — never trusted for
                    // authorization by itself, only for telling the two
                    // cases apart (see moveRoomGridAssignment()).
                    current_section_id: props.section.id,
                    cross_section_confirmed: targetCrossSection,
                    clear_merge_link: clearMergeLink,
                },
                { crossSection: targetCrossSection, silent: true },
            );
            if (!rowOk) allOk = false;
        }
        savingCell.value = null;

        if (allOk) {
            const summary = moveTargets.length > 1
                ? `${block.subject_code ?? 'Schedule'} moved for ${moveTargets.map((t) => t.section_code).join(' & ')}.`
                : (anyCrossSection
                    ? `${block?.subject_code ?? 'Schedule'} moved for ${moveTargets[0].section_code ?? 'the other section'}.`
                    : 'Schedule updated.');
            toast.add({ severity: 'success', summary: 'Scheduled', detail: summary, life: 3000 });
        }
        // On failure, writeSchedule's own error toast already explains
        // why (Room/Faculty/Section conflict, capacity, etc.) — the
        // grid re-reads from loadRoomSchedule() either way, so a
        // failed move simply leaves the block exactly where the server
        // still has it (no local optimistic mutation to roll back).
        return;
    }

    if (mode === 'new') {
        const row = draggingRow.value;
        draggingRow.value = null;
        if (!row) return;

        // INTELLIGENT IRREGULAR SECTION SCHEDULING, drag-and-drop entry
        // point — mirrors the "Merge Recommendation" flow already
        // available from the Subjects tab (IrregularSectionMergeService
        // via the same merge-recommendation/merge endpoints), just
        // triggered by dropping directly onto an already-occupied slot
        // instead of clicking a button. Only offered when: this Section
        // is Irregular (merging only ever makes sense in that
        // direction — a Regular section's class is the host, never the
        // guest), the target slot belongs to a DIFFERENT section
        // (blockAt(...).is_current_section === false), and it's the
        // exact same Subject. Anything else (different subject, own
        // section's own block, empty slot) falls through to the normal
        // Assign modal/conflict path unchanged.
        const targetBlock = blockAt(day, rowIndex);
        if (
            isIrregularSection.value &&
            targetBlock &&
            !targetBlock.is_current_section &&
            targetBlock.subject_code === row.subject?.subject_code
        ) {
            await attemptMergeDrop(row, targetBlock);
            return;
        }

        openAssignModal(row, day, rowIndex);
    }
};

// Section prop only ever carries section_type as a plain string (see
// Show.vue's props), so this mirrors isIrregularSection in Show.vue
// rather than depending on it directly.
const isIrregularSection = computed(() => props.section.section_type === 'Irregular');

const attemptMergeDrop = async (row, targetBlock) => {
    let recommendation;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge-recommendation', [props.section.id, row.id]),
            { headers: { Accept: 'application/json' } },
        );
        recommendation = await response.json();
        if (!response.ok) throw new Error(recommendation.message ?? 'Could not check merge compatibility.');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not check merge compatibility.', life: 6000 });
        return;
    }

    const candidate = (recommendation.candidates ?? [])
        .find((c) => c.section_subject_id === targetBlock.section_subject_id);

    if (!candidate || !candidate.compatible) {
        toast.add({
            severity: 'warn',
            summary: 'Not a Compatible Merge',
            detail: candidate?.blocking_reason
                ?? `This class can't be merged into ${targetBlock.section_code}'s ${targetBlock.subject_code} session — try Merge Recommendation on the Subjects tab for the full reason, or drop into an empty slot to schedule it independently.`,
            life: 8000,
        });
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Merge into this class?',
        html: `<div class="text-left text-sm">Instead of a new booking, <strong>${row.subject?.subject_code ?? 'this subject'}</strong> will join <strong>${targetBlock.section_code}</strong>'s existing class here — same Faculty, Room, and Time. No separate Room/Faculty booking is created.</div>`,
        showCancelButton: true,
        confirmButtonText: 'Merge',
        cancelButtonText: 'Cancel',
        customClass: { container: 'roomgrid-swal-on-top' },
    });
    if (!result.isConfirmed) return;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge', [props.section.id, row.id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ target_section_subject_id: targetBlock.section_subject_id }),
            },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply merge.');

        toast.add({ severity: 'success', summary: 'Merged', detail: data.message, life: 5000 });
        (data.sectionSubjects ?? []).forEach((fresh) => emit('row-updated', fresh));
        await loadRoomSchedule(selectedRoom.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not apply merge.', life: 6000 });
    }
};

// Same flow as attemptMergeDrop(), but for dragging an EXISTING placed
// block onto another block instead of dropping a fresh unscheduled
// subject. mergeRecommendation()/applyMerge() are scoped to the
// DRAGGED block's own Section (block.section_id) — never
// props.section.id, the currently-VIEWED Room Grid's section, since a
// cross-section-authorized user can drag a block that belongs to a
// different Section than the one they have open (see
// onDragStartBlock()'s "authorization is per-assignment" note above).
const attemptMergeDropExisting = async (block, targetBlock) => {
    let recommendation;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge-recommendation', [block.section_id, block.section_subject_id]),
            { headers: { Accept: 'application/json' } },
        );
        recommendation = await response.json();
        if (!response.ok) throw new Error(recommendation.message ?? 'Could not check merge compatibility.');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not check merge compatibility.', life: 6000 });
        return;
    }

    const candidate = (recommendation.candidates ?? [])
        .find((c) => c.section_subject_id === targetBlock.section_subject_id);

    if (!candidate || !candidate.compatible) {
        toast.add({
            severity: 'warn',
            summary: 'Not a Compatible Merge',
            detail: candidate?.blocking_reason
                ?? `${block.section_code}'s ${block.subject_code} can't be merged into ${targetBlock.section_code}'s session — try Merge Recommendation on ${block.section_code}'s Subjects tab for the full reason, or drop into an empty slot to move it independently.`,
            life: 8000,
        });
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Merge into this class?',
        html: `<div class="text-left text-sm">Instead of moving <strong>${block.section_code}</strong>'s own booking, <strong>${block.subject_code ?? 'this subject'}</strong> will join <strong>${targetBlock.section_code}</strong>'s existing class here — same Faculty, Room, and Time. Its separate Room/Faculty booking will be dropped.</div>`,
        showCancelButton: true,
        confirmButtonText: 'Merge',
        cancelButtonText: 'Cancel',
        customClass: { container: 'roomgrid-swal-on-top' },
    });
    if (!result.isConfirmed) return;

    try {
        const response = await fetch(
            route('scheduling.section-subjects.merge', [block.section_id, block.section_subject_id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ target_section_subject_id: targetBlock.section_subject_id }),
            },
        );
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Could not apply merge.');

        toast.add({ severity: 'success', summary: 'Merged', detail: data.message, life: 5000 });
        (data.sectionSubjects ?? []).forEach((fresh) => emit('row-updated', fresh));
        await loadRoomSchedule(selectedRoom.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.message ?? 'Could not apply merge.', life: 6000 });
    }
};

const onDragOverCell = (event) => event.preventDefault();

/* ------------------------------------------------------------------ */
/* Assign modal — Hours/Week, Meetings/Week, Days, and Faculty for a   */
/* newly-dropped subject. Duration per meeting = Hours/Week divided by */
/* Meetings/Week, applied identically to every selected Day, matching  */
/* the single start/end-time-per-row model the rest of the app uses.   */
/* ------------------------------------------------------------------ */

const assignModalVisible = ref(false);
const assignSaving = ref(false);
const assignForm = ref({
    row: null,
    day: null,
    rowIndex: null,
    hoursPerWeek: 3,
    requiredHours: 3,
    meetingsPerWeek: 1,
    selectedDays: [],
    facultyId: null,
    editing: false, // true when opened by clicking an existing block, not dragging a new one
});

const meetingsOptions = [1, 2].map((n) => ({ label: `${n}x / week`, value: n }));

// Faculty ranking — same tiered recommendation source the Subjects tab's
// FacultyRecommendationSelector uses (RecommendationService, scoped by
// College/Department — e.g. only CCS faculty tier as "College Match"
// for a CCS subject), fetched read-only via the existing
// `faculty-options` endpoint. This is intentionally NOT the same as
// FacultyRecommendationSelector's AutoComplete: that component applies
// a pick immediately via a separate `faculty-override` POST, which
// would fight with this modal's own "Save Changes" button and the
// batched Room/Days/Time/Faculty write in confirmAssign(). Here the
// fetched tiers only decide labels/sort order in the existing Select —
// the actual save still goes through writeSchedule() exactly as before.
const facultyRecommendations = ref([]); // [{ id, tier/badge, score, college, ... }]
const facultyRecommendationsLoading = ref(false);

const recommendedBadge = (f) => {
    if (f.tier === 'teaching_qualification') return 'Qualified Faculty';
    if (f.tier === 'college_match') return 'College Match';
    if (f.tier === 'general_education_match') return 'General Education Match';
    return f.selected_by_college_match ? 'College Match' : 'Qualified Faculty';
};

// Same badge-color mapping as FacultyRecommendationSelector, kept in
// sync intentionally — a badge should mean the same thing everywhere
// in the app.
const facultyBadgeSeverity = (badge) => {
    switch (badge) {
        case 'Qualified Faculty':
            return 'success';
        case 'College Match':
        case 'General Education Match':
            return 'info';
        case 'Manual Override':
            return 'warning';
        default:
            return 'secondary';
    }
};

const loadFacultyRecommendations = async (sectionSubjectId) => {
    if (!sectionSubjectId) {
        facultyRecommendations.value = [];
        return;
    }
    facultyRecommendationsLoading.value = true;
    try {
        const response = await fetch(
            route('scheduling.section-subjects.faculty-options', [props.section.id, sectionSubjectId]),
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();
        facultyRecommendations.value = (data.recommended ?? []).map((f) => ({
            ...f,
            badge: recommendedBadge(f),
        }));
    } catch (e) {
        facultyRecommendations.value = []; // fall back to the plain full-name-sorted list below
    } finally {
        facultyRecommendationsLoading.value = false;
    }
};

const facultyOptions = computed(() => {
    const recommended = new Map(facultyRecommendations.value.map((f) => [f.id, f]));
    return [...props.activeFaculty]
        .map((f) => {
            const rec = recommended.get(f.id);
            return {
                ...f,
                recommended: !!rec,
                badge: rec?.badge ?? null,
                score: rec?.score ?? null,
                college: rec?.college ?? f.college_name ?? null,
            };
        })
        .sort((a, b) => (b.recommended - a.recommended) || (b.score ?? -1) - (a.score ?? -1) || a.full_name.localeCompare(b.full_name));
});

const openAssignModal = (row, day, rowIndex) => {
    const subject = row.subject || {};
    const defaultHours = (Number(subject.lecture_hours) || 0) + (Number(subject.laboratory_hours) || 0) || 3;

    assignForm.value = {
        row,
        day,
        rowIndex,
        hoursPerWeek: defaultHours,
        requiredHours: defaultHours,
        meetingsPerWeek: 1,
        selectedDays: [day],
        facultyId: row.faculty_id ?? null,
        editing: false,
    };
    assignModalVisible.value = true;
    loadFacultyRecommendations(row.id);
};

// Click an existing block belonging to this section to edit its
// Faculty, Hours/Week, Meetings/Week, and Days — the same form the
// drag-a-new-subject flow uses, prefilled from the block's current
// values, writing through the same updateSchedule() endpoint. Start
// time/slot and Room stay as-is here; use drag-to-move for those.
const openEditModal = (block) => {
    const row = props.rows.find((r) => r.id === block.section_subject_id);
    if (!row) return;

    const subject = row.subject || {};
    const requiredHours = (Number(subject.lecture_hours) || 0) + (Number(subject.laboratory_hours) || 0) || 3;
    const rowDays = (row.days || []);
    const meetingsPerWeek = rowDays.length || 1;
    const perMeetingHours = ((toMinutes(row.end_time) - toMinutes(row.start_time)) || 60) / 60;
    const startTime = row.start_time || hourRows.value[0];
    const rowIndex = Math.max(0, hourRows.value.indexOf(startTime));

    assignForm.value = {
        row,
        day: null, // no fixed/locked day in edit mode — all days toggle freely
        rowIndex,
        hoursPerWeek: Math.round(perMeetingHours * meetingsPerWeek * 100) / 100,
        requiredHours,
        meetingsPerWeek,
        selectedDays: [...rowDays],
        facultyId: row.faculty_id ?? null,
        editing: true,
    };
    assignModalVisible.value = true;
    loadFacultyRecommendations(row.id);
};

// Keep the dropped day always selected; toggling others in/out of the
// meeting pattern, capped at Meetings/Week.
const toggleAssignDay = (day) => {
    if (day === assignForm.value.day) return; // the dropped day/slot is fixed
    const selected = assignForm.value.selectedDays;
    const idx = selected.indexOf(day);
    if (idx !== -1) {
        selected.splice(idx, 1);
    } else if (selected.length < assignForm.value.meetingsPerWeek) {
        selected.push(day);
    }
};

watch(() => assignForm.value.meetingsPerWeek, (n) => {
    // Trim extra days if Meetings/Week was lowered below the current
    // selection; always keep the originally-dropped day — but only
    // when there IS one. In edit mode (openEditModal) `day` is
    // deliberately null since no day is locked, so forcing it back in
    // would splice a `null` into selectedDays — which Laravel's
    // `days.0` validation then rejects as "invalid", surfacing as a
    // Conflict toast with no visible cause. Only re-pin the dropped
    // day when we actually have one (the drag-a-new-subject flow).
    if (assignForm.value.selectedDays.length > n) {
        assignForm.value.selectedDays = assignForm.value.selectedDays.slice(0, Math.max(1, n));
        if (assignForm.value.day && !assignForm.value.selectedDays.includes(assignForm.value.day)) {
            assignForm.value.selectedDays[0] = assignForm.value.day;
        }
    }

    // Raising Meetings/Week raises the per-meeting floor too (see
    // minHoursPerWeek) — bump Hours/Week up if the current total
    // would now split into sub-1-hr sessions.
    if (Number(assignForm.value.hoursPerWeek) < minHoursPerWeek.value) {
        assignForm.value.hoursPerWeek = minHoursPerWeek.value;
    }
});

const assignDaysValid = computed(() => assignForm.value.selectedDays.length === assignForm.value.meetingsPerWeek);

// Mirrors the server's own check (SectionSubjectController) so the
// Registrar sees the mismatch — and why Save is disabled — before
// clicking, instead of after a round-trip Conflict toast.
// A single meeting shorter than this isn't a real class session (e.g.
// a 30-min slot). This is a floor on PER-MEETING duration — since
// Hours/Week is split across Meetings/Week, the Hours/Week input's
// own minimum must scale with Meetings/Week so a 2x/week subject
// can't be dragged down to two 30-min sessions via a 1-hr weekly
// total.
const MIN_SESSION_HOURS = 1;

const minHoursPerWeek = computed(() => {
    const floor = MIN_SESSION_HOURS * (assignForm.value.meetingsPerWeek || 1);
    // Never let the floor exceed the curriculum's own required hours
    // — a subject with fewer required hours than the floor would
    // otherwise be impossible to save at all.
    return Math.min(floor, assignForm.value.requiredHours || floor);
});

const assignHoursValid = computed(() => Number(assignForm.value.hoursPerWeek) === Number(assignForm.value.requiredHours));

const perMeetingPreview = computed(() => {
    const step = intervalMinutes.value;
    const totalMinutes = (assignForm.value.hoursPerWeek || 0) * 60;
    const minutes = Math.max(step, Math.round((totalMinutes / assignForm.value.meetingsPerWeek) / step) * step);
    return minutes / 60;
});

const confirmAssign = async () => {
    const { row, rowIndex, hoursPerWeek, meetingsPerWeek, selectedDays, requiredHours } = assignForm.value;
    if (!row || !assignDaysValid.value || !hoursPerWeek || hoursPerWeek <= 0) return;

    // Weekly Hours Mismatch — confirmable, not a hard block. A
    // Registrar may intentionally trim/extend a meeting because of
    // Room/Faculty availability constraints; this just makes sure
    // they see the mismatch before it's saved, same "flagged, not
    // blocked" pattern as the Subjects tab's batch save uses.
    if (!assignHoursValid.value) {
        // Hide the Schedule Subject dialog while the confirm popup is
        // up rather than relying on z-index to win against PrimeVue's
        // own dynamically-assigned Dialog stacking — guarantees "Save
        // Anyway" is always reachable regardless of stacking context.
        assignModalVisible.value = false;
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Weekly Hours Mismatch',
            html: `<div class="text-left text-sm">This schedule totals ${hoursPerWeek} hrs/week, but ${row.subject?.subject_code ?? 'this subject'} requires ${requiredHours} hrs/week.</div>`,
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back',
            confirmButtonColor: '#dc2626',
            customClass: { container: 'roomgrid-swal-on-top' },
        });
        if (!result.isConfirmed) {
            assignModalVisible.value = true; // reopen so they can adjust
            return;
        }
    }

    // Duration per meeting, rounded to the nearest 30 minutes so the
    // grid (hourly rows) still reads cleanly, minimum 30 minutes.
    const totalMinutes = hoursPerWeek * 60;
    // Round to the nearest grid interval (matches the Academic Term's
    // Time Interval setting) so the resulting block always lands
    // cleanly on a grid row.
    const step = intervalMinutes.value;
    const perMeeting = Math.max(step, Math.round((totalMinutes / meetingsPerWeek) / step) * step);
    const startTime = hourRows.value[rowIndex];
    const endTime = toHHMM(toMinutes(startTime) + perMeeting);

    assignSaving.value = true;
    const ok = await writeSchedule(row.id, {
        room_id: selectedRoom.value.id,
        days: selectedDays,
        start_time: startTime,
        end_time: endTime,
        faculty_id: assignForm.value.facultyId,
        hours_confirmed: !assignHoursValid.value,
    }, { successMessage: `${row.subject?.subject_code ?? 'Subject'} ${assignForm.value.editing ? 'updated' : 'scheduled'}.` });
    assignSaving.value = false;

    // If the write itself failed (e.g. a Faculty/Room conflict caught
    // server-side), bring the form back so they can see the error
    // toast and adjust rather than being left with nothing on screen.
    assignModalVisible.value = !ok;
};

// Remove this block from the room — only ever reachable in Edit mode
// (an existing placement). This does NOT delete the Subject from the
// Section; it just clears Room/Days/Time/Faculty back to unscheduled
// via the same updateSchedule() endpoint everything else here uses, so
// the row goes back to "Unscheduled Subjects" and can be re-placed
// later. A Practicum row is never placed on this grid in the first
// place (see UpdateSectionSubjectScheduleRequest), so no special-case
// is needed here for that.
const assignRemoving = ref(false);

const removeAssignment = async () => {
    const { row } = assignForm.value;
    if (!row) return;

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Remove from Room?',
        html: `<div class="text-left text-sm">This will unassign <strong>${row.subject?.subject_code ?? 'this subject'}</strong> from ${roomLabel(selectedRoom.value)}. It will go back to Unscheduled Subjects — you can place it again anytime.</div>`,
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        customClass: { container: 'roomgrid-swal-on-top' },
    });
    if (!result.isConfirmed) return;

    assignRemoving.value = true;
    const ok = await writeSchedule(row.id, {
        room_id: null,
        days: [],
        start_time: null,
        end_time: null,
        faculty_id: null,
    }, { successMessage: `${row.subject?.subject_code ?? 'Subject'} removed from ${roomLabel(selectedRoom.value)}.` });
    assignRemoving.value = false;

    if (ok) {
        assignModalVisible.value = false;
    }
};
</script>

<template>
    <div class="flex flex-col lg:flex-row gap-4">
        <!-- LEFT SIDEBAR: Room search / selection -->
        <div class="w-full lg:w-40 shrink-0 space-y-3">
            <div>
                <span class="relative w-full block">
                    <i class="pi pi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <InputText v-model="roomSearch" placeholder="Search rooms" class="neu-inset w-full !rounded-lg !border-none !pl-8 !text-xs !py-1.5" />
                </span>
            </div>

            <div class="neu-inset rounded-xl p-2.5">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Recommended Rooms</p>
                <div class="flex items-center gap-3 text-[9px] text-slate-400 mb-1.5">
                    <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-sky-400"></span>Lecture</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-purple-400"></span>Laboratory</span>
                </div>
                <div v-if="roomsLoading" class="text-xs text-slate-400 py-1.5">Loading…</div>
                <div v-else-if="recommendedRooms.length === 0" class="text-xs text-slate-400 py-1.5">No department rooms found.</div>
                <ul v-else class="space-y-1">
                    <li
                        v-for="room in recommendedRooms"
                        :key="room.id"
                        class="rounded-md px-2 py-1.5 cursor-pointer text-xs border transition-colors"
                        :class="selectedRoom?.id === room.id
                            ? 'bg-blue-50 border-blue-200 text-blue-700 font-medium'
                            : `border-transparent hover:bg-slate-50 text-slate-700 ${roomTypeAccentClass(room)}`"
                        @click="selectRoom(room)"
                    >
                        <div class="font-medium truncate flex items-center gap-1.5">
                            <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :class="roomTypeDotClass(room)"></span>
                            {{ roomLabel(room) }}
                        </div>
                        <div class="text-[10px] text-slate-400">{{ room.room_type }} · Cap {{ room.capacity }}</div>
                    </li>
                </ul>
            </div>

            <div v-if="roomSearch && searchResults.length" class="neu-inset rounded-xl p-2.5">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Other / Shared Rooms</p>
                <ul class="space-y-1">
                    <li
                        v-for="room in searchResults"
                        :key="room.id"
                        class="rounded-md px-2 py-1.5 cursor-pointer text-xs border transition-colors"
                        :class="selectedRoom?.id === room.id
                            ? 'bg-blue-50 border-blue-200 text-blue-700 font-medium'
                            : `border-transparent hover:bg-slate-50 text-slate-700 ${roomTypeAccentClass(room)}`"
                        @click="selectRoom(room)"
                    >
                        <div class="font-medium flex items-center gap-1.5 truncate">
                            <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :class="roomTypeDotClass(room)"></span>
                            {{ roomLabel(room) }}
                            <Tag v-if="room.is_outside_department" value="Override" severity="warning" class="!text-[9px] !py-0" />
                        </div>
                        <div class="text-[10px] text-slate-400">{{ room.department_name || room.college_name || room.room_type }} · Cap {{ room.capacity }}</div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- MAIN AREA: Selected room's weekly timetable -->
        <div class="flex-1 min-w-0">
            <div v-if="!selectedRoom" class="h-64 flex items-center justify-center text-slate-400 text-sm neu-inset rounded-xl">
                Select a room on the left to view and schedule its weekly timetable.
            </div>
            <div v-else>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-slate-800 flex items-center gap-2">
                            {{ roomLabel(selectedRoom) }}
                            <Tag v-if="isManualOverride" value="Manual Room Override" severity="warning" class="!text-[10px]" />
                        </h3>
                        <p class="text-xs text-slate-400">{{ selectedRoom.room_type }} · Capacity {{ selectedRoom.capacity }}</p>
                    </div>
                    <ProgressSpinner v-if="gridLoading" style="width: 22px; height: 22px" strokeWidth="6" />
                </div>

                <div class="overflow-x-auto border border-slate-300 rounded-xl">
                    <div
                        class="grid text-[13px]"
                        :style="{
                            gridTemplateColumns: `92px repeat(${days.length}, minmax(130px, 1fr))`,
                            gridTemplateRows: `36px repeat(${hourRows.length}, 24px)`,
                        }"
                    >
                        <!-- Header row -->
                        <div class="border-b border-r border-slate-300 bg-slate-100"></div>
                        <div
                            v-for="day in days"
                            :key="`h-${day}`"
                            class="border-b border-r border-slate-300 bg-slate-100 flex items-center justify-center font-bold text-slate-700"
                        >
                            {{ dayLabels[day] || day }}
                        </div>

                        <!-- Time labels — each row shows its own start–end
                             range (e.g. "8:00 AM – 8:30 AM") so it's always
                             clear exactly which slot a block occupies. -->
                        <template v-for="(hour, rowIndex) in hourRows" :key="`t-${hour}`">
                            <div
                                v-if="!isLunchRow(hour) || isFirstLunchRow(rowIndex)"
                                class="border-r border-b border-slate-300 flex items-center justify-end pr-1.5 leading-none whitespace-nowrap text-[9px]"
                                :class="isLunchRow(hour) ? 'text-amber-700 font-bold' : (hour.endsWith(':00') ? 'text-slate-700 font-semibold' : 'text-slate-500 font-medium')"
                                :style="{ gridColumn: 1, gridRow: isLunchRow(hour) ? `${rowIndex + 2} / span ${lunchSpan}` : rowIndex + 2 }"
                            >
                                {{ isLunchRow(hour) ? lunchRangeLabel : formatSlotRange(hour) }}
                            </div>
                        </template>

                        <!-- Drop-target cells + placed blocks -->
                        <template v-for="(day, dIndex) in days" :key="`col-${day}`">
                            <template v-for="(hour, rowIndex) in hourRows" :key="`cell-${day}-${hour}`">
                                <div
                                    v-if="!isCovered(day, rowIndex) && (!isLunchRow(hour) || isFirstLunchRow(rowIndex))"
                                    class="border-r border-b border-slate-300 relative"
                                    :class="isLunchRow(hour) ? 'bg-amber-100' : ''"
                                    :style="{
                                        gridColumn: dIndex + 2,
                                        gridRow: isLunchRow(hour)
                                            ? `${rowIndex + 2} / span ${lunchSpan}`
                                            : (blockAt(day, rowIndex) ? `${rowIndex + 2} / span ${blockAt(day, rowIndex).span}` : rowIndex + 2),
                                    }"
                                    @dragover="isLunchRow(hour) ? null : onDragOverCell($event)"
                                    @drop="isLunchRow(hour) ? null : onDrop(day, rowIndex)"
                                >
                                    <div
                                        v-if="savingCell === `${day}-${rowIndex}`"
                                        class="absolute inset-0.5 rounded-md bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-500"
                                    >
                                        <i class="pi pi-spin pi-spinner"></i>
                                    </div>
                                    <div
                                        v-else-if="blockAt(day, rowIndex)"
                                        class="absolute inset-0.5 rounded-md px-2 py-1 overflow-hidden text-[11px] leading-tight group"
                                        :class="blockClass(blockAt(day, rowIndex))"
                                        :draggable="blockAt(day, rowIndex).can_edit"
                                        :title="blockTitle(blockAt(day, rowIndex))"
                                        @dragstart="onDragStartBlock(blockAt(day, rowIndex))"
                                        @click="blockAt(day, rowIndex).is_current_section && !blockAt(day, rowIndex).is_finalized ? openEditModal(blockAt(day, rowIndex)) : null"
                                    >
                                        <i
                                            v-if="blockHasConflict(blockAt(day, rowIndex))"
                                            class="pi pi-exclamation-triangle absolute top-1 right-1 text-[10px] text-red-600"
                                        ></i>
                                        <i
                                            v-else-if="blockAt(day, rowIndex).is_finalized"
                                            class="pi pi-lock absolute top-1 right-1 text-[9px] text-amber-700 opacity-80"
                                        ></i>
                                        <i
                                            v-else-if="blockAt(day, rowIndex).is_current_section"
                                            class="pi pi-pencil absolute top-1 right-1 text-[9px] opacity-0 group-hover:opacity-60"
                                        ></i>
                                        <i
                                            v-else-if="!blockAt(day, rowIndex).can_edit"
                                            class="pi pi-lock absolute top-1 right-1 text-[9px] opacity-60"
                                        ></i>
                                        <i
                                            v-else
                                            class="pi pi-arrows-alt absolute top-1 right-1 text-[9px] opacity-0 group-hover:opacity-60"
                                        ></i>
                                        <div class="font-semibold truncate">{{ blockAt(day, rowIndex).subject_code }}</div>
                                        <div class="truncate">
                                            {{ blockAt(day, rowIndex).section_code }}
                                        </div>
                                        <div v-if="blockAt(day, rowIndex).faculty_name" class="truncate text-[10px] opacity-75">{{ blockAt(day, rowIndex).faculty_name }}</div>
                                        <div v-if="blockHasConflict(blockAt(day, rowIndex))" class="text-[9px] font-semibold text-red-700 truncate">⚠ Conflict — needs rescheduling</div>
                                        <div v-else-if="blockAt(day, rowIndex).is_finalized" class="text-[9px] italic opacity-75 truncate">Finalized — locked</div>
                                        <div v-else-if="!blockAt(day, rowIndex).can_edit" class="text-[9px] italic opacity-75 truncate">Outside your scheduling scope</div>
                                    </div>
                                </div>
                            </template>
                        </template>

                        <!-- Single "LUNCH" banner spanning the whole merged
                             lunch row and every day column. -->
                        <template v-for="(hour, rowIndex) in hourRows" :key="`lunch-${hour}`">
                            <div
                                v-if="isLunchRow(hour) && isFirstLunchRow(rowIndex)"
                                class="flex items-center justify-center text-xs font-bold text-amber-800 tracking-wider select-none pointer-events-none"
                                :style="{ gridColumn: `2 / span ${days.length}`, gridRow: `${rowIndex + 2} / span ${lunchSpan}` }"
                            >
                                LUNCH
                            </div>
                        </template>
                    </div>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">
                    Drag a subject from "Unscheduled Subjects" onto a slot to place it, or drag an existing block to move it. Click a block to edit its Faculty, Hours/Week, Meetings/Week, or Days.
                    Schedules belonging to any section within your authorized scheduling scope can be moved from here, even if it isn't the currently selected section — schedules outside your scope stay locked, and a finalized section's schedule (amber, padlock) stays locked for everyone until an Admin/Registrar unlocks it. The Lunch Break slot is fixed and can't be scheduled into.
                </p>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: Draggable unscheduled subjects for this section -->
        <div class="w-full lg:w-40 shrink-0 neu-inset rounded-xl p-2.5">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Unscheduled Subjects</p>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[9px] text-slate-400 mb-1.5">
                <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Major</span>
                <span class="flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-400"></span>Minor / GenEd</span>
            </div>
            <div v-if="unscheduledSubjects.length === 0" class="text-xs text-slate-400 py-1.5">
                Every subject in {{ section.section_code }} is placed.
            </div>
            <ul v-else class="space-y-1">
                <li
                    v-for="row in unscheduledSubjects"
                    :key="row.id"
                    :draggable="!section.is_finalized"
                    class="rounded-md px-2 py-1.5 text-xs border border-slate-200 cursor-grab hover:border-blue-300 hover:bg-blue-50/40"
                    :class="subjectCategoryAccentClass(row)"
                    :title="section.is_finalized ? 'This section is finalized and can\'t be edited.' : (!selectedRoom ? 'Select a room first' : 'Drag onto the grid')"
                    @dragstart="section.is_finalized ? null : onDragStartNew(row)"
                >
                    <div class="font-medium text-slate-700 truncate flex items-center gap-1.5">
                        <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :class="subjectCategoryDotClass(row)"></span>
                        {{ row.subject?.subject_code }}
                    </div>
                    <div class="text-[10px] text-slate-400 truncate">{{ row.subject?.subject_title }}</div>
                </li>
            </ul>
        </div>

        <!-- Assign / Edit modal — Hours/Week, Meetings/Week, Days, Faculty -->
        <Dialog
            v-model:visible="assignModalVisible"
            modal
            :header="assignForm.editing ? 'Edit Schedule' : 'Schedule Subject'"
            :style="{ width: '30rem' }"
        >
            <div v-if="assignForm.row" class="space-y-4">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Subject</p>
                    <p class="text-sm font-medium text-slate-700">
                        {{ assignForm.row.subject?.subject_code }} — {{ assignForm.row.subject?.subject_title }}
                    </p>
                    <p class="text-xs text-slate-400">
                        {{ roomLabel(selectedRoom) }} · starting {{ formatHourLabel(hourRows[assignForm.rowIndex]) }}
                        <span v-if="!assignForm.editing"> on {{ dayLabels[assignForm.day] }}</span>
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-500 mb-1 block">Hours / Week</label>
                        <InputNumber v-model="assignForm.hoursPerWeek" :min="minHoursPerWeek" :max="assignForm.requiredHours" :step="0.5" showButtons class="w-full" />
                        <p class="text-[11px] text-slate-400 mt-1">
                            Curriculum load for {{ assignForm.row?.subject?.subject_code ?? 'this subject' }}: up to
                            <button
                                v-if="!assignHoursValid"
                                type="button"
                                class="font-semibold text-blue-600 underline underline-offset-2"
                                @click="assignForm.hoursPerWeek = assignForm.requiredHours"
                            >{{ assignForm.requiredHours }} hrs/week</button>
                            <span v-else class="font-medium text-slate-500">{{ assignForm.requiredHours }} hrs/week</span>
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-500 mb-1 block">Meetings / Week</label>
                        <Select
                            v-model="assignForm.meetingsPerWeek"
                            :options="meetingsOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                        />
                    </div>
                </div>

                <div>
                    <label class="text-xs font-medium text-slate-500 mb-1 block">
                        Days ({{ assignForm.selectedDays.length }}/{{ assignForm.meetingsPerWeek }} selected)
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="day in days"
                            :key="day"
                            type="button"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors"
                            :class="assignForm.selectedDays.includes(day)
                                ? 'bg-blue-500 border-blue-500 text-white'
                                : 'bg-white border-slate-200 text-slate-600 hover:border-blue-300'"
                            :disabled="day === assignForm.day"
                            :title="day === assignForm.day ? 'This is the slot you dropped on' : undefined"
                            @click="toggleAssignDay(day)"
                        >
                            {{ dayLabels[day] || day }}
                        </button>
                    </div>
                    <p v-if="!assignDaysValid" class="text-xs text-amber-600 mt-1">
                        Select exactly {{ assignForm.meetingsPerWeek }} day(s) to match Meetings/Week.
                    </p>
                    <p v-if="!assignHoursValid" class="text-xs text-amber-600 mt-1">
                        Hours/Week doesn't match {{ assignForm.row?.subject?.subject_code ?? 'this subject' }}'s required {{ assignForm.requiredHours }} hrs/week — you'll be asked to confirm before saving.
                    </p>
                </div>

                <div>
                    <label class="text-xs font-medium text-slate-500 mb-1 block">
                        Faculty
                        <i v-if="facultyRecommendationsLoading" class="pi pi-spin pi-spinner text-[10px] text-slate-400 ml-1"></i>
                    </label>
                    <Select
                        v-model="assignForm.facultyId"
                        :options="facultyOptions"
                        optionLabel="full_name"
                        optionValue="id"
                        filter
                        showClear
                        placeholder="Unassigned"
                        class="w-full"
                    >
                        <template #option="{ option }">
                            <div class="flex flex-col gap-0.5 py-0.5 w-full">
                                <div class="flex items-center gap-2">
                                    <span>{{ option.full_name }}</span>
                                    <Tag
                                        v-if="option.badge"
                                        :value="option.badge"
                                        :severity="facultyBadgeSeverity(option.badge)"
                                        class="!text-[10px]"
                                    />
                                </div>
                                <span v-if="option.college" class="text-[10px] text-slate-400">{{ option.college }}</span>
                            </div>
                        </template>
                    </Select>
                </div>

                <p class="text-[11px] text-slate-400">
                    Each meeting runs {{ perMeetingPreview }} hr(s), starting at {{ formatHourLabel(hourRows[assignForm.rowIndex]) }} on every selected day.
                    <span v-if="assignForm.editing">Drag the block on the grid to change its start time or room.</span>
                </p>
            </div>

            <template #footer>
                <Button
                    v-if="assignForm.editing"
                    label="Remove from Room"
                    icon="pi pi-trash"
                    text
                    severity="danger"
                    :loading="assignRemoving"
                    class="mr-auto"
                    @click="removeAssignment"
                />
                <Button label="Cancel" text severity="secondary" @click="assignModalVisible = false" />
                <Button
                    :label="assignForm.editing ? 'Save Changes' : 'Save Schedule'"
                    icon="pi pi-check"
                    :loading="assignSaving"
                    :disabled="!assignDaysValid || !assignForm.hoursPerWeek"
                    @click="confirmAssign"
                />
            </template>
        </Dialog>
    </div>
</template>

<style>
/* Swal2's default z-index (1060) can sit underneath PrimeVue's
   Dialog portal, which assigns its own z-index dynamically and is
   often higher — making confirm dialogs like "Weekly Hours Mismatch"
   render behind the Schedule Subject modal and unclickable. This is
   global (unscoped) because Swal2 mounts outside this component's
   DOM tree, so a scoped style wouldn't reach it. */
.roomgrid-swal-on-top {
    z-index: 2147483647 !important;
}
</style>