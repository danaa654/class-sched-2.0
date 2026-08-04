<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAcademicTermRequest extends FormRequest
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
        return [
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * Mirrors StoreAcademicTermRequest but excludes the Academic Term
     * being edited (the {academicTerm} route parameter) from the
     * duplicate-combination check.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $schoolYearId = $this->input('school_year_id');
            $semesterId = $this->input('semester_id');
            $academicTermId = $this->route('academicTerm')?->id;

            if (is_numeric($schoolYearId) && is_numeric($semesterId)) {
                $exists = AcademicTerm::withTrashed()
                    ->where('school_year_id', $schoolYearId)
                    ->where('semester_id', $semesterId)
                    ->where('id', '!=', $academicTermId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('semester_id', 'This School Year and Semester combination already exists.');
                }
            }
        });
    }
}