<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use App\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSectionRequest extends FormRequest
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
        // The {section} route parameter is available via the bound model.
        $sectionId = $this->route('section')?->id;

        return [
            'section_code' => [
                'required',
                'string',
                'max:20',
                // Scoped to the same academic_year + semester — same
                // reasoning as StoreSectionRequest: two Sections in
                // different terms may legitimately share a code.
                // whereNull('deleted_at') — same reasoning as
                // StoreSectionRequest: don't let a soft-deleted
                // Section's old code block reuse.
                Rule::unique('sections', 'section_code')
                    ->ignore($sectionId)
                    ->where('academic_year', $this->input('academic_year'))
                    ->where('semester', $this->input('semester'))
                    ->whereNull('deleted_at'),
            ],
            'section_name' => ['required', 'string', 'max:255'],
            'section_type' => ['required', Rule::in(StoreSectionRequest::SECTION_TYPES)],
            'major_id' => ['required', 'integer', 'exists:majors,id'],
            'curriculum_id' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Regular'),
                'nullable',
                'integer',
                'exists:curriculums,id',
            ],
            'year_level' => ['required', Rule::in(StoreSectionRequest::YEAR_LEVELS)],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', Rule::in(StoreSectionRequest::SEMESTERS)],
            'estimated_students' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * Cross-field checks: the selected Curriculum must belong to the
     * selected Major, and the selected Academic Year + Semester must
     * correspond to a real AcademicTerm.
     *
     * Unlike StoreSectionRequest/StoreSectionBatchRequest (which only
     * ever create a brand-new Section, and so require a non-Archived
     * term), this allows an Archived term to count as a match —
     * otherwise simply re-saving an unrelated field (e.g. Estimated
     * Students, Remarks) on a Section that already belongs to a term
     * which has since been Archived would be wrongly rejected.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('curriculum_id') || ! $this->filled('major_id')) {
                return;
            }

            $curriculum = Curriculum::find($this->input('curriculum_id'));

            if ($curriculum && (int) $curriculum->major_id !== (int) $this->input('major_id')) {
                $validator->errors()->add('curriculum_id', 'The selected curriculum does not belong to the selected major.');
            }
        });

        $validator->after(function (Validator $validator) {
            if (! $this->filled('academic_year') || ! $this->filled('semester')) {
                return;
            }

            if (! AcademicTerm::existsForSection($this->input('academic_year'), $this->input('semester'), allowArchived: true)) {
                $validator->errors()->add('academic_year', 'No Academic Term matches this Academic Year and Semester. Set up the Academic Term first under Academic Calendar.');
            }
        });
    }
}