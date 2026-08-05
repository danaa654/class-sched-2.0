<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacultyQualificationsRequest extends FormRequest
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
            'subject_ids' => ['present', 'array'],
            // Only Active subjects may be assigned as a qualification —
            // an inactive subject shouldn't be teachable going forward.
            'subject_ids.*' => [
                'integer',
                Rule::exists('subjects', 'id')->where('is_active', true),
            ],
        ];
    }
}