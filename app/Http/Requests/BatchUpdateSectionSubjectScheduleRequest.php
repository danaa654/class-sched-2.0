<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the "Save Schedule" batch submit from the Section Subjects
 * scheduling workspace (Prompt 8.4 — Manual Scheduling Per Subject).
 *
 * Unlike UpdateSectionSubjectScheduleRequest (one field, one row, saved
 * instantly on change), this request carries every row the Registrar
 * has edited in the table at once. Shape checking only happens here —
 * the actual Faculty/Room conflict checks still run per row inside
 * SectionSubjectController::batchUpdateSchedule(), since they depend
 * on data already in the database (and on sibling rows in this same
 * batch), not just on the shape of the payload.
 */
class BatchUpdateSectionSubjectScheduleRequest extends FormRequest
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['required', 'integer', 'exists:section_subjects,id'],
            'rows.*.faculty_id' => ['nullable', 'integer', 'exists:faculties,id'],
            'rows.*.room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'rows.*.days' => ['nullable', 'array'],
            'rows.*.days.*' => [Rule::in(self::DAY_TOKENS)],
            'rows.*.start_time' => ['nullable', 'date_format:H:i'],
            'rows.*.end_time' => ['nullable', 'date_format:H:i', 'after:rows.*.start_time'],
            'rows.*.capacity' => ['nullable', 'integer', 'min:1'],
            // Set true once the Registrar has explicitly acknowledged a
            // Room Capacity warning (Section Capacity > Room Capacity) for
            // this row — lets that row save despite the warning. See
            // SectionSubjectController::batchUpdateSchedule().
            'rows.*.capacity_confirmed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.end_time.after' => 'End Time must be later than Start Time.',
            'rows.*.capacity.min' => 'Capacity must be at least 1.',
        ];
    }
}