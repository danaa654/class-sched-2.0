<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurriculumRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Derive end_year from start_year before validation runs, so the
     * stored range is always internally consistent (Start Year + 4).
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('start_year')) {
            $this->merge([
                'end_year' => (int) $this->input('start_year') + 4,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'major_id' => ['required', 'exists:majors,id'],
            'code' => ['required', 'string', 'max:50', 'unique:curriculums,code'],
            'name' => ['required', 'string', 'max:255'],
            'start_year' => [
                'required',
                'integer',
                'between:2000,2100',
                Rule::unique('curriculums', 'start_year')->where(
                    fn ($query) => $query->where('major_id', $this->input('major_id')),
                ),
            ],
            'end_year' => ['required', 'integer', 'between:2000,2104'],
            'status' => ['required', Rule::in(['Draft', 'Active', 'Archived'])],
            'allow_new_students' => ['boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom error message for the start-year uniqueness rule, since
     * the underlying rule is on a scoped (major_id, start_year) pair.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_year.unique' => 'This Major already has a Curriculum for that Start Year.',
        ];
    }
}