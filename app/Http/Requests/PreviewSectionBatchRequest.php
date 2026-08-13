<?php

namespace App\Http\Requests;

use App\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewSectionBatchRequest extends FormRequest
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
            'major_id' => ['required', 'integer', 'exists:majors,id'],
            'section_type' => ['required', Rule::in(StoreSectionRequest::SECTION_TYPES)],
            // Required for Regular sections; optional/reference-only
            // for Irregular sections.
            'curriculum_id' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Regular'),
                'nullable',
                'integer',
                'exists:curriculums,id',
            ],
            'year_level' => ['required', Rule::in(StoreSectionRequest::YEAR_LEVELS)],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', Rule::in(StoreSectionRequest::SEMESTERS)],
            'section_prefix' => ['required', 'string', 'max:20'],
            // Only meaningful for Regular sections (A/B/C block
            // generation). An Irregular section is a single
            // scheduling group — see
            // SectionBatchGeneratorService::nextIrregularName() — so
            // neither field applies to it.
            'number_of_blocks' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Regular'),
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
            'estimated_students_per_block' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Regular'),
                'nullable',
                'integer',
                'min:1',
            ],
            // Irregular counterpart of estimated_students_per_block —
            // a single count for the one section being generated,
            // defaulting to 5 in the UI (spec section 3) but always
            // required here once section_type is Irregular.
            'estimated_students' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Irregular'),
                'nullable',
                'integer',
                'min:1',
            ],
            // Present when re-previewing while editing one Section
            // that already exists in the batch — excludes it from its
            // own "already used" check.
            'exclude_section_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('curriculum_id') || ! $this->filled('major_id')) {
                return;
            }

            $curriculum = Curriculum::find($this->input('curriculum_id'));

            if ($curriculum && (int) $curriculum->major_id !== (int) $this->input('major_id')) {
                $validator->errors()->add('curriculum_id', 'The selected prospectus does not belong to the selected program.');
            }
        });
    }
}