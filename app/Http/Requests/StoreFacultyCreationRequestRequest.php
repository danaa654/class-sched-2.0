<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a Dean/OIC/Assistant Dean's "Request New Faculty" form.
 * Field rules deliberately mirror StoreFacultyRequest (the
 * Admin/Registrar direct-create path) — a Creation request must hold
 * a proposed Faculty record to the exact same standard the record
 * will be validated against again on approval (see
 * FacultyRequestController::review()).
 */
class StoreFacultyCreationRequestRequest extends FormRequest
{
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
            'faculty_id' => ['required', 'string', 'max:20', 'unique:faculties,faculty_id'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'employment_type' => ['required', Rule::in(['Full-time', 'Part-time'])],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            // Dean/OIC/Assistant Dean have no direct write path to the
            // load ceiling — the proposed max is capped by employment
            // type (Part-time: 18, Full-time: 24); a higher ceiling goes
            // through FacultyLoadRequest afterward, same rule as
            // StoreFacultyRequest/FacultyController.
            'max_teaching_units' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.($this->input('employment_type') === 'Part-time' ? 18 : 24),
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}