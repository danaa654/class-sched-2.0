<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSchoolYearRequest extends FormRequest
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
        ];
    }

    /**
     * Configure the validator instance.
     *
     * Mirrors StoreSchoolYearRequest: pins the Start/End Year gap to
     * exactly one year, and blocks renaming into a name collision with
     * another School Year (the {schoolYear} route parameter is excluded).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $startYear = $this->input('start_year');
            $endYear = $this->input('end_year');
            $schoolYearId = $this->route('schoolYear')?->id;

            if (is_numeric($startYear) && is_numeric($endYear) && ($endYear - $startYear) !== 1) {
                $validator->errors()->add('end_year', 'End Year must be exactly one year after Start Year.');

                return;
            }

            if (is_numeric($startYear) && is_numeric($endYear)) {
                $name = "{$startYear}-{$endYear}";

                $exists = \App\Models\SchoolYear::withTrashed()
                    ->where('name', $name)
                    ->where('id', '!=', $schoolYearId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('start_year', "School Year {$name} already exists.");
                }
            }
        });
    }
}