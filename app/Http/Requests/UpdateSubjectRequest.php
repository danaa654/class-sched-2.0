<?php

namespace App\Http\Requests;

use App\Support\RoomCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
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
        // The {subject} route parameter is available via the bound model.
        $subjectId = $this->route('subject')?->id;

        return [
            'subject_code' => ['required', 'string', 'max:20', Rule::unique('subjects', 'subject_code')->ignore($subjectId)],
            'subject_title' => ['required', 'string', 'max:255'],
            'major_id' => ['nullable', 'required_if:category,Major', 'exists:majors,id'],
            'category' => ['required', Rule::in(['Major', 'General Education'])],
            'units' => ['required', 'integer', 'min:0'],
            'lecture_hours' => ['required', 'integer', 'min:0'],
            'laboratory_hours' => ['required', 'integer', 'min:0'],
            'preferred_room_category' => ['nullable', Rule::in(RoomCategories::LIST)],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * General Education subjects are shared across all majors and are
     * never tied to one — force major_id to null regardless of what the
     * client sends when that category is selected.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('category') === 'General Education') {
            $this->merge(['major_id' => null]);
        }
    }
}