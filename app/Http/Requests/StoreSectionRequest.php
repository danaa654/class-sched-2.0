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
            'section_code' => ['required', 'string', 'max:20', 'unique:sections,section_code'],
            'section_name' => ['required', 'string', 'max:255'],
            'major_id' => ['required', 'integer', 'exists:majors,id'],
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', Rule::in(self::YEAR_LEVELS)],
            'academic_year' => ['required', 'string', 'max:20'],
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