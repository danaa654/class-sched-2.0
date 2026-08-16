<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use App\Models\SchoolYear;
use App\Models\Semester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAcademicTermRequest extends FormRequest
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
     * The Academic Term form is the single place School Year, Semester,
     * and the School Year's Scheduling Preferences are all entered
     * together. `semester` is one of the three hardcoded Semester::NAMES
     * (no Semester picker/lookup needed) — see
     * AcademicTermController@resolveSemester for how it resolves to a
     * Semester record.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_year' => ['required', 'integer'],
            'end_year' => ['required', 'integer', 'gt:start_year'],
            'semester' => ['required', 'string', Rule::in(Semester::NAMES)],
            'status' => ['required', Rule::in(['Active', 'Inactive', 'Archived'])],
            'remarks' => ['nullable', 'string', 'max:255'],

            // Scheduling Preferences — read by the Auto Schedule AI
            // from the Active School Year (see SchoolYear::active()).
            // Time Interval is not part of the payload: it's fixed at
            // 30 Minutes (see SchoolYear::DEFAULT_TIME_INTERVAL_MINUTES,
            // always applied in AcademicTermController@resolveSchoolYear).
            'class_start_time' => ['required', 'date_format:H:i'],
            'class_end_time' => ['required', 'date_format:H:i', 'after:class_start_time'],
            'available_days' => ['required', 'array', 'min:1'],
            'available_days.*' => [Rule::in(SchoolYear::ALL_DAYS)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_year.gt' => 'End Year must be later than Start Year.',
            'semester.required' => 'Please select a Semester.',
            'class_start_time.required' => 'Class Start Time is required.',
            'class_end_time.required' => 'Class End Time is required.',
            'class_end_time.after' => 'Class End Time must be later than Class Start Time.',
            'available_days.required' => 'Please select at least one Class Day.',
            'available_days.min' => 'Please select at least one Class Day.',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * - Pins the Start/End Year gap to exactly one year (the derived
     *   School Year name is always "{start}-{start+1}").
     * - Blocks a duplicate School Year + Semester combination. Since
     *   neither the School Year nor the Semester necessarily exist
     *   yet at validation time, this is resolved by name rather than
     *   by id.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $startYear = $this->input('start_year');
            $endYear = $this->input('end_year');
            $semesterName = $this->input('semester');

            if (is_numeric($startYear) && is_numeric($endYear) && ($endYear - $startYear) !== 1) {
                $validator->errors()->add('end_year', 'End Year must be exactly one year after Start Year.');

                return;
            }

            if (is_numeric($startYear) && is_numeric($endYear) && $semesterName) {
                $schoolYearId = SchoolYear::withTrashed()
                    ->where('name', "{$startYear}-{$endYear}")
                    ->value('id');

                $semesterId = Semester::withTrashed()
                    ->where('name', $semesterName)
                    ->value('id');

                if ($schoolYearId && $semesterId) {
                    $exists = AcademicTerm::withTrashed()
                        ->where('school_year_id', $schoolYearId)
                        ->where('semester_id', $semesterId)
                        ->exists();

                    if ($exists) {
                        $validator->errors()->add('semester', 'This School Year and Semester combination already exists.');
                    }
                }
            }
        });
    }
}