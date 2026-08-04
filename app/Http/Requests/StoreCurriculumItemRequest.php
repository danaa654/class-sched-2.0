<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurriculumItemRequest extends FormRequest
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
        // The {curriculum} route parameter is available via the bound model.
        $curriculumId = $this->route('curriculum')?->id;

        return [
            'subject_id' => [
                'required',
                'exists:subjects,id',
                Rule::unique('curriculum_items', 'subject_id')->where(
                    fn ($query) => $query->where('curriculum_id', $curriculumId),
                ),
            ],
            'year_level' => ['required', Rule::in(['1st Year', '2nd Year', '3rd Year', '4th Year'])],
            'semester' => ['required', Rule::in(['First Semester', 'Second Semester', 'Summer'])],
            'prerequisite_subject_id' => ['nullable', 'exists:subjects,id', 'different:subject_id'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_id.unique' => 'This Subject is already part of this Curriculum.',
            'prerequisite_subject_id.different' => 'A Subject cannot be its own prerequisite.',
        ];
    }
}