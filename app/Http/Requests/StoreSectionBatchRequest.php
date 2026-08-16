<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use App\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSectionBatchRequest extends FormRequest
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
            // for Irregular sections — an Irregular section's subjects
            // are picked manually rather than loaded from one Prospectus.
            'curriculum_id' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Regular'),
                'nullable',
                'integer',
                'exists:curriculums,id',
            ],
            'year_level' => ['required', Rule::in(StoreSectionRequest::YEAR_LEVELS)],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', Rule::in(StoreSectionRequest::SEMESTERS)],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string'],

            // The editable preview list — one row per section block.
            'sections' => ['required', 'array', 'min:1', 'max:100'],
            'sections.*.section_code' => [
                'required',
                'string',
                'max:20',
                // Scoped to the same academic_year + semester — same
                // reasoning as StoreSectionRequest: two Sections in
                // different terms may legitimately share a code (this
                // batch's own academic_year/semester fields apply to
                // every row it generates).
                Rule::unique('sections', 'section_code')
                    ->where('academic_year', $this->input('academic_year'))
                    ->where('semester', $this->input('semester'))
                    ->whereNull('deleted_at'),
            ],
            'sections.*.estimated_students' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('curriculum_id') && $this->filled('major_id')) {
                $curriculum = Curriculum::find($this->input('curriculum_id'));

                if ($curriculum && (int) $curriculum->major_id !== (int) $this->input('major_id')) {
                    $validator->errors()->add('curriculum_id', 'The selected prospectus does not belong to the selected program.');
                }
            }

            // The actual safety net behind the Add Section form's
            // Academic Year/Semester dropdowns (now sourced from real
            // AcademicTerm records — see
            // SectionController::academicTermSectionOptions()): a
            // direct/scripted request could still submit a combination
            // with no AcademicTerm behind it, or one that's Archived,
            // and this batch generator is only ever creating brand-new
            // Sections, so Archived terms don't count as a match here.
            if ($this->filled('academic_year') && $this->filled('semester')) {
                if (! AcademicTerm::existsForSection($this->input('academic_year'), $this->input('semester'), allowArchived: false)) {
                    $validator->errors()->add('academic_year', 'No active or inactive Academic Term matches this Academic Year and Semester. Set up the Academic Term first under Academic Calendar.');
                }
            }

            // Catch duplicate names within the submitted batch itself
            // (e.g. the admin edited two rows to the same name) — the
            // per-row "unique" rule only checks against the database.
            $codes = collect($this->input('sections', []))
                ->pluck('section_code')
                ->filter()
                ->map(fn ($code) => strtoupper(trim((string) $code)));

            $duplicates = $codes->duplicates();

            if ($duplicates->isNotEmpty()) {
                foreach ($this->input('sections', []) as $index => $section) {
                    $code = strtoupper(trim((string) ($section['section_code'] ?? '')));

                    if ($duplicates->contains($code)) {
                        $validator->errors()->add("sections.{$index}.section_code", 'This section name is used more than once in this batch.');
                    }
                }
            }
        });
    }
}