<?php

namespace App\Http\Requests;

use App\Models\FacultyLoadRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewFacultyLoadRequestRequest extends FormRequest
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
        $cap = FacultyLoadRequest::effectiveCapFor($this->user());

        return [
            'decision' => ['required', Rule::in(['Approved', 'Denied'])],
            // Optional on approval, but required on denial so the
            // requester knows why (matches the "note" spirit of the
            // original ask — reviewers should leave a reason too).
            'decision_note' => ['nullable', 'string', 'max:1000', 'required_if:decision,Denied'],
            // Admin/Registrar aren't locked into granting exactly what
            // was requested — they can approve a different (lower or
            // higher, up to their current cap) ceiling than the
            // Dean/OIC asked for. Null means "grant exactly what was
            // requested" — see FacultyLoadRequestController::review().
            'approved_max_teaching_units' => ['nullable', 'integer', 'min:1', 'max:'.$cap],
            'approved_max_weekly_hours' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        $cap = FacultyLoadRequest::effectiveCapFor($this->user());

        return [
            'decision_note.required_if' => 'Please leave a short note explaining why this request is being denied.',
            'approved_max_teaching_units.max' => 'Cannot exceed the current maximum teaching load ceiling of '.$cap.' units.',
        ];
    }
}