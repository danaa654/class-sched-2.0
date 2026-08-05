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
            'college_id' => ['required', 'exists:colleges,id'],
            'department_id' => [
                'required',
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