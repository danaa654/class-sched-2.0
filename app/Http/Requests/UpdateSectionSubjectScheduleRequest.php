<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a single inline field edit made on the Subject Assignment
 * workspace's scheduling spreadsheet (Faculty, Room, Days, Start/End
 * Time, or Capacity). The frontend sends one field at a time as the
 * user edits a cell, so only the fields actually present are
 * validated/applied — see SectionSubjectController::updateSchedule().
 */
class UpdateSectionSubjectScheduleRequest extends FormRequest
{
    /**
     * Days are stored as a comma-separated string of these tokens,
     * e.g. "Mon,Wed".
     *
     * @var list<string>
     */
    public const DAY_TOKENS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public function authorize(): bool
    {
        return true;
    }

    private static function windowStart(): string
    {
        return SchoolYear::active()?->classStartTime() ?? SchoolYear::DEFAULT_CLASS_START_TIME;
    }

    private static function windowEnd(): string
    {
        return SchoolYear::active()?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'faculty_id' => ['sometimes', 'nullable', 'integer', 'exists:faculties,id'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'days' => ['sometimes', 'nullable', 'array'],
            'days.*' => [Rule::in(self::DAY_TOKENS)],
            // Hard-bounded to the Active School Year's Scheduling
            // Window (Class Start/End Time) — a manual edit on the
            // spreadsheet can never save a time outside it, matching
            // the same boundary enforced on the Auto Generate review
            // panel's Day & Time editor (SectionSubjectController::overrideTime()).
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i', 'after_or_equal:' . self::windowStart()],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i', 'after:start_time', 'before_or_equal:' . self::windowEnd()],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Set true once the Registrar has explicitly acknowledged a
            // Room Capacity warning (Section Capacity > Room Capacity) —
            // lets the save proceed despite the warning. See
            // SectionSubjectController::updateSchedule().
            'capacity_confirmed' => ['sometimes', 'boolean'],
            // Set true once the Registrar has explicitly acknowledged a
            // Weekly Hours Mismatch (scheduled Days x Time doesn't add
            // up to the Subject's required weekly hours) — lets the
            // save proceed despite the warning. See
            // SectionSubjectController::updateSchedule().
            'hours_confirmed' => ['sometimes', 'boolean'],
            // Set true once an Administrator has explicitly acknowledged
            // a Teaching Load Limit warning ("⚠ Teaching Load Limit
            // Exceeded") — lets the save proceed despite the faculty
            // member being over their Maximum Teaching Load. Only
            // honored when the authenticated user has the Administrator
            // role — see SectionSubjectController::workloadWarningFor().
            'workload_confirmed' => ['sometimes', 'boolean'],
            // The Section the Room Grid is CURRENTLY OPEN for — required
            // on the Room Grid move endpoint so the backend can
            // independently tell a same-section move from a
            // cross-section one (see SectionPolicy::moveScheduleAssignment()
            // and SectionSubjectController::moveRoomGridAssignment()).
            // Never trusted for authorization on its own — it is only
            // ever compared against the schedule assignment's OWN
            // section_id, which is loaded server-side from the
            // database.
            'current_section_id' => ['sometimes', 'nullable', 'integer', 'exists:sections,id'],
            // Set true once the user has explicitly confirmed the
            // "Move Schedule Assignment?" modal warning that this
            // move will modify ANOTHER Section's schedule. Required by
            // the backend before a cross-section move is saved — see
            // moveRoomGridAssignment(). Mirrors the existing
            // capacity_confirmed / hours_confirmed / workload_confirmed
            // acknowledgement pattern above.
            'cross_section_confirmed' => ['sometimes', 'boolean'],
            // Set true when the Room Grid's merged-block drag chose
            // "Move only <this section>" instead of "Move both
            // sections (keep merged)" — see RoomGrid.vue's onDrop() and
            // SectionSubjectController::performScheduleAssignmentUpdate().
            // Detaches this row's is_merged/merged_into_section_subject_id
            // link WITHOUT touching its Faculty/Room/Days/Time (unlike
            // IrregularSectionMergeService::unmerge(), which clears the
            // schedule back to Draft) — the row keeps whatever slot this
            // same request just moved it to.
            'clear_merge_link' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.after' => 'End Time must be later than Start Time.',
            'start_time.after_or_equal' => 'Start Time must be within this School Year\'s Scheduling Window (starts at ' . self::windowStart() . ').',
            'end_time.before_or_equal' => 'End Time must be within this School Year\'s Scheduling Window (ends at ' . self::windowEnd() . ').',
            'capacity.min' => 'Capacity must be at least 1.',
        ];
    }

    /**
     * A Practicum/OJT row is explicitly non-room-based (see
     * Subject::isPracticum()) — never allow a Room to be attached to
     * it via the manual spreadsheet editor, mirroring the same rule
     * the Auto Schedule engine follows.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sectionSubject = $this->route('subject');
            $subject = $sectionSubject?->subject;

            if ($subject?->isPracticum() && $this->filled('room_id')) {
                $validator->errors()->add(
                    'room_id',
                    'This subject is Practicum / OJT and is conducted off-campus — it cannot be assigned a classroom or laboratory room.'
                );
            }
        });
    }
}