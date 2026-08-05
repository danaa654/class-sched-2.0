<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionSubjectRequest extends FormRequest
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
     * Accepts a batch of Subjects to place into the Section at once —
     * used by both "Load From Curriculum" (after the user trims the
     * preview) and "Manual Selection" (possibly multiple subjects).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $section = $this->route('section');

        return [
            'source' => ['required', Rule::in(['Curriculum', 'Manual'])],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => [
                'integer',
                'distinct',
                'exists:subjects,id',
                // A Subject cannot be duplicated within the same Section.
                Rule::unique('section_subjects', 'subject_id')->where('section_id', $section?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_ids.*.unique' => 'One or more selected subjects are already part of this section.',
            'subject_ids.*.distinct' => 'Duplicate subjects were selected.',
        ];
    }
}