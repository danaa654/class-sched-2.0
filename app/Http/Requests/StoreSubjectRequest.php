<?php

namespace App\Http\Requests;

use App\Models\Major;
use App\Support\AccessScope;
use App\Support\RoomCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubjectRequest extends FormRequest
{
    /**
     * Authorization is finalized in the Controller via
     * SubjectPolicy::createOfCategory (needs the resolved college_id,
     * which prepareForValidation() below may have just forced) — this
     * request only guarantees the payload is well-formed and internally
     * consistent for the user's role, per the "do not rely only on
     * hiding fields in the frontend" requirement.
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
        return [
            'subject_code' => ['required', 'string', 'max:20', 'unique:subjects,subject_code'],
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
            // Delivery type: 'regular' (classroom/laboratory, the
            // existing behavior) or 'practicum' (Practicum/OJT/
            // Internship/Fieldwork/Clinical Practice, off-campus).
            'subject_type' => ['sometimes', Rule::in(['regular', 'practicum'])],
            'units' => ['required', 'integer', 'min:0'],
            'lecture_hours' => ['required', 'integer', 'min:0'],
            'laboratory_hours' => ['required', 'integer', 'min:0'],
            // Required only for Practicum/OJT — the total off-campus
            // hours the student must complete (e.g. 240).
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
     * Prepare the data for validation.
     *
     * Dean/OIC are College-scoped: their College is forced to their
     * assigned college_id regardless of what the client sends, and
     * their Category is forced to "Major" — mirroring the read-only
     * College field / restricted Category select in the UI, but as
     * the actual authorization boundary (spec's "important security
     * rule": never trust the frontend alone).
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (AccessScope::isCollegeScoped($user)) {
            $this->merge([
                'college_id' => $user->college_id,
                'category' => 'Major',
            ]);
        }

        if ($this->input('category') !== 'Major') {
            $this->merge(['major_ids' => $this->input('major_ids', [])]);
        }

        $this->merge(['subject_type' => $this->input('subject_type', 'regular')]);

        // Practicum-only fields never linger on a Regular subject —
        // mirrors the "Do not overcomplicate" rule and keeps the
        // scheduler's isPracticum() check the single source of truth
        // instead of stray leftover data.
        if ($this->input('subject_type') !== 'practicum') {
            $this->merge([
                'required_hours' => null,
                'deployment_type' => null,
            ]);
        }
    }

    /**
     * Cross-field checks that need the whole payload at once:
     *  - a role may only submit a category it's allowed to manage
     *  - the selected Major(s) must actually belong to the selected College
     */
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