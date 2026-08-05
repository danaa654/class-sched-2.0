<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /**
     * Display the Rooms page.
     *
     * This is a master list of classrooms, laboratories, and other
     * facilities for later use by the scheduling engine. No schedule,
     * faculty, section, or timeslot assignment happens here — this
     * page only stores room information.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('room_search', ''));

        $rooms = Room::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%")
                        ->orWhere('building', 'like', "%{$search}%")
                        ->orWhere('room_type', 'like', "%{$search}%");
                });
            })
            ->orderBy('room_code')
            ->paginate(10, ['*'], 'room_page')
            ->withQueryString();

        return Inertia::render('Scheduling/Rooms/Index', [
            'rooms' => $rooms,
            'filters' => ['room_search' => $search],
            'roomTypes' => StoreRoomRequest::ROOM_TYPES,
        ]);
    }

    /**
     * Store a newly created room in the Room Master.
     */
    public function store(StoreRoomRequest $request): RedirectResponse
    {
        Room::create($request->validated());

        return redirect()->route('scheduling.rooms')->with('success', 'Room added successfully.');
    }

    /**
     * Update an existing room in the Room Master.
     */
    public function update(UpdateRoomRequest $request, Room $room): RedirectResponse
    {
        $room->update($request->validated());

        return redirect()->route('scheduling.rooms')->with('success', 'Room updated successfully.');
    }

    /**
     * Delete a room from the Room Master.
     */
    public function destroy(Room $room): RedirectResponse
    {
        $room->delete();

        return redirect()->route('scheduling.rooms')->with('success', 'Room deleted successfully.');
    }
}