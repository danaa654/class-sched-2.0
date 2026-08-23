<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewFacultyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['Approved', 'Rejected'])],
            // Required when rejecting, so the requester always gets a
            // reason back (spec Section 4/5 — "Record rejection
            // reason").
            'decision_note' => [
                Rule::requiredIf(fn () => $this->input('decision') === 'Rejected'),
                'nullable', 'string', 'max:2000',
            ],
        ];
    }
}