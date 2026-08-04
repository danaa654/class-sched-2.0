<?php

namespace App\Http\Requests;

use App\Models\Semester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSemesterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'short_name' => ['required', 'string', 'max:30'],
            'display_order' => ['required', 'integer'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * Mirrors StoreSemesterRequest but excludes the Semester being edited
     * (the {semester} route parameter) from the uniqueness checks.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = $this->input('name');
            $displayOrder = $this->input('display_order');
            $semesterId = $this->route('semester')?->id;

            if (is_string($name) && $name !== '') {
                $exists = Semester::withTrashed()
                    ->where('name', $name)
                    ->where('id', '!=', $semesterId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'A semester with this name already exists.');
                }
            }

            if (is_numeric($displayOrder)) {
                $exists = Semester::withTrashed()
                    ->where('display_order', $displayOrder)
                    ->where('id', '!=', $semesterId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('display_order', 'This display order is already in use.');
                }
            }
        });
    }
}