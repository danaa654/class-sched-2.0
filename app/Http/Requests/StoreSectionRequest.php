<?php

namespace App\Http\Requests;

use App\Models\Curriculum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSectionRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const YEAR_LEVELS = [
        'First Year',
        'Second Year',
        'Third Year',
        'Fourth Year',
    ];

    /**
     * Matches curriculum_items.semester's enum exactly, so a Section's
     * own Semester lines up with the Curriculum data used by
     * "Generate Curriculum Subjects".
     *
     * @var list<string>
     */
    public const SEMESTERS = ['First Semester', 'Second Semester', 'Summer'];

    /**
     * 'Regular' sections get one uniform block schedule the normal
     * way. 'Irregular' sections have their subjects scheduled one at
     * a time by IrregularSectionMergeService during Auto Generate —
     * merged into a compatible Regular section's class where
     * possible, or an independent schedule otherwise. See
     * IrregularSectionMergeService's docblock.
     *
     * @var list<string>
     */
    public const SECTION_TYPES = ['Regular', 'Irregular'];

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
        return [
            'section_code' => [
                'required',
                'string',
                'max:20',
                // whereNull('deleted_at') — a soft-deleted Section's code
                // must be reusable. The bare 'unique:' rule queries the
                // raw table and, unlike Eloquent's own SoftDeletes
                // scope, does NOT exclude trashed rows on its own, so a
                // deleted Section would otherwise permanently block its
                // own code from ever being used again.
                Rule::unique('sections', 'section_code')->whereNull('deleted_at'),
            ],
            'section_name' => ['required', 'string', 'max:255'],
            'section_type' => ['required', Rule::in(self::SECTION_TYPES)],
            'major_id' => ['required', 'integer', 'exists:majors,id'],
            // Required for Regular sections; optional/reference-only
            // for Irregular sections, whose subjects are picked
            // manually rather than loaded from one Prospectus.
            'curriculum_id' => [
                Rule::requiredIf(fn () => $this->input('section_type') === 'Regular'),
                'nullable',
                'integer',
                'exists:curriculums,id',
            ],
            'year_level' => ['required', Rule::in(self::YEAR_LEVELS)],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', Rule::in(self::SEMESTERS)],
            'estimated_students' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * Cross-field check: the selected Curriculum must belong to the
     * selected Major.
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
    }
}