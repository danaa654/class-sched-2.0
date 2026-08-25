<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendFacultyScheduleEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Spec section 18: only Administrator/Registrar may send official
        // faculty schedule emails.
        return (bool) $this->user()?->hasAnyRole(['Administrator', 'Registrar']);
    }

    public function rules(): array
    {
        return [
            'faculty_id' => ['required', 'integer', 'exists:faculties,id'],
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }
}