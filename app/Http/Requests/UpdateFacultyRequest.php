<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacultyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     *
     * General Education Faculty don't belong to a specific College or
     * Department — force both to null server-side regardless of what
     * the client sent, so the rule stays authoritative rather than the UI.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('faculty_category') === 'General Education Faculty') {
            $this->merge([
                'college_id' => null,
                'department_id' => null,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The {faculty} route parameter is available via the bound model.
        $facultyId = $this->route('faculty')?->id;

        return [
            'faculty_id' => ['required', 'string', 'max:20', Rule::unique('faculties', 'faculty_id')->ignore($facultyId)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'employment_type' => ['required', Rule::in(['Full-time', 'Part-time', 'Contractual'])],
            'faculty_category' => ['required', Rule::in(['Department Faculty', 'General Education Faculty'])],
            // College/Department are only required for Department Faculty.
            // General Education Faculty leave both null (cleared above).
            'college_id' => [
                Rule::requiredIf($this->input('faculty_category') === 'Department Faculty'),
                'nullable',
                'exists:colleges,id',
            ],
            'department_id' => [
                Rule::requiredIf($this->input('faculty_category') === 'Department Faculty'),
                'nullable',
                Rule::exists('departments', 'id')->where('college_id', $this->input('college_id')),
            ],
            'specialization' => ['nullable', 'string', 'max:255'],
            'max_teaching_units' => ['required', 'integer', 'min:0', 'max:255'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}