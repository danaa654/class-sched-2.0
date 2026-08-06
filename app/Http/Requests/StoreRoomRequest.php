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
            // Optional — RecommendationService::recommendRooms() already
            // falls back to a plain Lecture/Laboratory Room Type match
            // when a Room has no fine-grained category set, so this was
            // never meant to be a hard requirement. The Add/Edit Room
            // form doesn't currently collect it at all, so leaving this
            // `required` blocked every Add/Edit Room save.
            'room_category' => ['nullable', Rule::in(RoomCategories::LIST)],
            // College & Department/Program Assignment. college_id = null
            // means "All Colleges" (a shared room). department_id = null
            // means "All Programs" within the selected College — and, when
            // a specific Department IS given, it must actually belong to
            // that College so a room can never end up pointing at, say,
            // BSIT while assigned to the College of Criminology.
            'college_id' => ['nullable', 'exists:colleges,id'],
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')->when(
                    $this->filled('college_id'),
                    fn ($rule) => $rule->where('college_id', $this->input('college_id'))
                ),
            ],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string'],
        ];
    }
}