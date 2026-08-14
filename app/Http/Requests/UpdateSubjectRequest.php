<?php

namespace App\Http\Requests;

use App\Models\Major;
use App\Support\AccessScope;
use App\Support\RoomCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSubjectRequest extends FormRequest
{
    /**
     * Final authorization happens in the Controller via
     * SubjectPolicy::update(), which checks the subject's *existing*
     * ownership (category + college) before any of this payload is
     * applied — so a Dean can never use this request to "grab"
     * another College's subject in the first place.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subjectId = $this->route('subject')?->id;

        return [
            'subject_code' => ['required', 'string', 'max:20', Rule::unique('subjects', 'subject_code')->ignore($subjectId)],
            'subject_title' => ['required', 'string', 'max:255'],
            'college_id' => [
                Rule::requiredIf(fn () => $this->input('category') === 'Major'),
                'nullable',
                'exists:colleges,id',
            ],
            'major_ids' => [
                Rule::requiredIf(fn () => $this->input('category') === 'Major'),
                'array',
            ],
            'major_ids.*' => ['integer', 'exists:majors,id'],
            'category' => ['required', Rule::in(['Major', 'General Education', 'Minor'])],
            'subject_type' => ['sometimes', Rule::in(['regular', 'practicum'])],
            'units' => ['required', 'integer', 'min:0'],
            'lecture_hours' => ['required', 'integer', 'min:0'],
            'laboratory_hours' => ['required', 'integer', 'min:0'],
            'required_hours' => [
                Rule::requiredIf(fn () => $this->input('subject_type') === 'practicum'),
                'nullable',
                'integer',
                'min:1',
            ],
            'deployment_type' => [
                Rule::requiredIf(fn () => $this->input('subject_type') === 'practicum'),
                'nullable',
                Rule::in(['on_campus', 'off_campus']),
            ],
            'deployment_remarks' => ['nullable', 'string'],
            'preferred_room_category' => ['nullable', Rule::in(RoomCategories::LIST)],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Dean/OIC can never change a subject's College — force it back to
     * the subject's own (pre-existing) college_id, and their existing
     * category, regardless of what the client submits. This makes the
     * "College field must be read-only for Dean/OIC" rule a backend
     * guarantee, not just a disabled input.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        $subject = $this->route('subject');

        if (AccessScope::isCollegeScoped($user)) {
            $this->merge([
                'college_id' => $subject?->college_id ?? $user->college_id,
                'category' => 'Major',
            ]);
        }

        if (AccessScope::isAssistantDean($user)) {
            // Assistant Dean may re-scope College/Major(s) for a
            // GenEd/Minor subject but can never turn it into a Major
            // subject via this request.
            $this->merge(['category' => $subject?->category === 'Major' ? $subject->category : $this->input('category')]);
        }

        if ($this->input('category') !== 'Major') {
            $this->merge(['major_ids' => $this->input('major_ids', [])]);
        }

        $this->merge(['subject_type' => $this->input('subject_type', $subject?->subject_type ?? 'regular')]);

        if ($this->input('subject_type') !== 'practicum') {
            $this->merge([
                'required_hours' => null,
                'deployment_type' => null,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            $category = $this->input('category');

            if (AccessScope::isAssistantDean($user) && $category === 'Major') {
                $validator->errors()->add('category', 'Assistant Dean may only manage GenEd and Minor subjects.');
            }

            if (AccessScope::isCollegeScoped($user) && $category !== 'Major') {
                $validator->errors()->add('category', 'Dean/OIC may only manage Major subjects.');
            }

            $collegeId = $this->input('college_id');
            $majorIds = array_filter((array) $this->input('major_ids', []));

            if ($collegeId && $majorIds) {
                $mismatched = Major::query()->whereIn('id', $majorIds)
                    ->whereHas('department', fn ($q) => $q->where('college_id', '!=', $collegeId))
                    ->exists();

                if ($mismatched) {
                    $validator->errors()->add('major_ids', 'Selected majors must belong to the selected College.');
                }
            }
        });
    }
}