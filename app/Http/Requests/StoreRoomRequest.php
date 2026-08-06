<?php

namespace App\Http\Requests;

use App\Support\RoomCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    /**
     * Room types available for scheduling matching later — a subject
     * needing lab time (e.g. Programming, Chemistry) gets matched to a
     * "Laboratory" room; a lecture-only subject gets matched to a
     * "Lecture" room. Finer distinctions (which lab, which building)
     * are captured in room_code/room_name instead of a longer enum.
     *
     * @var list<string>
     */
    public const ROOM_TYPES = [
        'Lecture',
        'Laboratory',
    ];

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
            'room_code' => ['required', 'string', 'max:20', 'unique:rooms,room_code'],
            'room_name' => ['required', 'string', 'max:255'],
            'building' => ['required', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:50'],
            'room_type' => ['required', Rule::in(self::ROOM_TYPES)],
            'room_category' => ['required', Rule::in(RoomCategories::LIST)],
            'department_id' => ['nullable', 'exists:departments,id'],
            'college_id' => ['nullable', 'exists:colleges,id'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string'],
        ];
    }
}