<?php

namespace App\Http\Requests;

use App\Models\FacultyLoadRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreFacultyLoadRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Coarse role gate here; FacultyLoadRequestPolicy::create() is
        // re-checked in the controller, and the College-scope check
        // against the target Faculty happens there too (needs the
        // resolved Faculty model, not just the route id).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cap = FacultyLoadRequest::effectiveCapFor($this->user());

        return [
            'faculty_id' => ['required', 'exists:faculties,id'],
            // Nullable so a request can raise units only, hours only,
            // or both — but at least one must actually be requested
            // (enforced in the controller against the faculty's
            // current values, since "which fields matter" depends on
            // the faculty's own workload_type).
            'requested_max_teaching_units' => ['nullable', 'integer', 'min:0', 'max:'.$cap],
            'requested_max_weekly_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        $cap = FacultyLoadRequest::effectiveCapFor($this->user());

        return [
            'reason.required' => 'Please explain why this faculty member needs a higher teaching load (e.g. which subjects/sections are short-staffed).',
            'reason.min' => 'Please give a bit more detail — a one-word reason isn\'t enough for the reviewer to approve this.',
            'requested_max_teaching_units.max' => 'Requests above '.$cap.' units cannot be submitted — that is the current maximum teaching load ceiling.',
        ];
    }
}