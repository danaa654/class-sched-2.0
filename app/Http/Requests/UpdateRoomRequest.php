<?php

namespace App\Http\Requests;

use App\Support\RoomCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
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
        // The {room} route parameter is available via the bound model.
        $roomId = $this->route('room')?->id;

        return [
            'room_code' => ['required', 'string', 'max:20', Rule::unique('rooms', 'room_code')->ignore($roomId)],
            'room_name' => ['required', 'string', 'max:255'],
            'building' => ['required', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:50'],
            'room_type' => ['required', Rule::in(StoreRoomRequest::ROOM_TYPES)],
            // See StoreRoomRequest — optional for the same reason.
            'room_category' => ['nullable', Rule::in(RoomCategories::LIST)],
            // See StoreRoomRequest for the College/Department rules —
            // department_id, when given, must belong to the selected
            // college_id (null college_id = "All Colleges", so no
            // Department restriction is checked in that case).
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