<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSchoolYearRequest extends FormRequest
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
            'start_year' => ['required', 'integer'],
            'end_year' => ['required', 'integer', 'gt:start_year'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],

            // Scheduling Preferences — read by the Auto Schedule AI
            // from the Active School Year (see SchoolYear::active()).
            'class_start_time' => ['required', 'date_format:H:i'],
            'class_end_time' => ['required', 'date_format:H:i', 'after:class_start_time'],
            'time_interval' => ['required', 'integer', Rule::in(SchoolYear::AVAILABLE_TIME_INTERVALS)],
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
            'class_start_time.required' => 'Class Start Time is required.',
            'class_end_time.required' => 'Class End Time is required.',
            'class_end_time.after' => 'Class End Time must be later than Class Start Time.',
            'time_interval.required' => 'Time Interval is required.',
            'available_days.required' => 'Please select at least one Class Day.',
            'available_days.min' => 'Please select at least one Class Day.',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * The "gt:start_year" rule only guarantees end_year is greater — it
     * doesn't pin the gap to exactly one year, so that's enforced here.
     * Also guards against a duplicate name (e.g. 2025-2026 already exists)
     * since the name is derived, not user-typed, and the migration's
     * unique constraint alone would surface as a raw SQL error.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $startYear = $this->input('start_year');
            $endYear = $this->input('end_year');

            if (is_numeric($startYear) && is_numeric($endYear) && ($endYear - $startYear) !== 1) {
                $validator->errors()->add('end_year', 'End Year must be exactly one year after Start Year.');

                return;
            }

            if (is_numeric($startYear) && is_numeric($endYear)) {
                $name = "{$startYear}-{$endYear}";

                $exists = SchoolYear::withTrashed()->where('name', $name)->exists();

                if ($exists) {
                    $validator->errors()->add('start_year', "School Year {$name} already exists.");
                }
            }
        });
    }
}