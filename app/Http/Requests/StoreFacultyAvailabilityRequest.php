<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultyAvailabilityRequest extends FormRequest
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
        $facultyId = $this->route('faculty')?->id;

        return [
            'day_of_week' => [
                'required',
                Rule::in(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']),
                // One record per day, per faculty member.
                Rule::unique('faculty_availabilities', 'day_of_week')->where('faculty_id', $facultyId),
            ],
            'is_available' => ['required', 'boolean'],
            'start_time' => ['nullable', 'required_if:is_available,true', 'date_format:H:i'],
            'end_time' => [
                'nullable',
                'required_if:is_available,true',
                'date_format:H:i',
                'after:start_time',
            ],
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
            'day_of_week.unique' => 'This faculty member already has an availability record for that day.',
            'end_time.after' => 'End time must be later than start time.',
            'start_time.required_if' => 'Start time is required when marked available.',
            'end_time.required_if' => 'End time is required when marked available.',
        ];
    }
}