<?php

namespace App\Http\Requests;

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
                Rule::unique('sections', 'section_code')->whereNull('deleted_at'),
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