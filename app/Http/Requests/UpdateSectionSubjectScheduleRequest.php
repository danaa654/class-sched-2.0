<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i', 'after:start_time'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.after' => 'End Time must be later than Start Time.',
            'capacity.min' => 'Capacity must be at least 1.',
        ];
    }
}