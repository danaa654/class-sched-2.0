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
            'room_category' => ['required', Rule::in(RoomCategories::LIST)],
            'department_id' => ['nullable', 'exists:departments,id'],
            'college_id' => ['nullable', 'exists:colleges,id'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'remarks' => ['nullable', 'string'],
        ];
    }
}