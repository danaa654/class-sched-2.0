<?php

namespace App\Http\Requests;

use App\Models\Semester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSemesterRequest extends FormRequest
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
     * Uniqueness on `name` and `display_order` is checked here (against
     * withTrashed()) so a soft-deleted Semester still blocks the value and
     * the user gets a friendly field-level message instead of a raw
     * database constraint error.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = $this->input('name');
            $displayOrder = $this->input('display_order');

            if (is_string($name) && $name !== '' && Semester::withTrashed()->where('name', $name)->exists()) {
                $validator->errors()->add('name', 'A semester with this name already exists.');
            }

            if (is_numeric($displayOrder) && Semester::withTrashed()->where('display_order', $displayOrder)->exists()) {
                $validator->errors()->add('display_order', 'This display order is already in use.');
            }
        });
    }
}