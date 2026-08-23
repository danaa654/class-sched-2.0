<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\College;
use App\Models\Department;
use App\Models\Room;
use App\Services\RoomUtilizationService;
use App\Support\RoomCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /** Roles that may see every Room regardless of College/Department scope. */
    private const UNSCOPED_ROLES = ['Administrator', 'Registrar'];

    public function __construct(private readonly RoomUtilizationService $utilization) {}

    /**
     * Display the Rooms page — now the authoritative resource-management
     * view for the scheduling engine: Utilization, Availability, a daily
     * breakdown, summary cards, and Room-aware filters, layered on top
     * of the existing Room Master CRUD.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Room::class);

        $search = trim((string) $request->query('room_search', ''));
        $quickFilter = (string) $request->query('quick_filter', 'all');
        $utilizationMin = $request->query('utilization_min');
        $utilizationMax = $request->query('utilization_max');
        $availabilityFilter = (string) $request->query('availability', '');

        $roomsQuery = Room::query()
            ->with(['department', 'college'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%")
                        ->orWhere('building', 'like', "%{$search}%")
                        ->orWhere('floor', 'like', "%{$search}%")
                        ->orWhere('room_type', 'like', "%{$search}%")
                        ->orWhere('room_category', 'like', "%{$search}%");
                });
            })
            ->when($request->query('building'), fn ($q, $v) => $q->where('building', $v))
            ->when($request->query('floor'), fn ($q, $v) => $q->where('floor', $v))
            ->when($request->query('room_type'), fn ($q, $v) => $q->where('room_type', $v))
            ->when($request->query('college_id'), fn ($q, $v) => $q->where('college_id', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v));

        $this->applyScope($roomsQuery, $request);

        // Utilization/Availability are computed per-room from live
        // schedules, so they can't be filtered in SQL — pull the
        // (already-filtered-by-search/building/etc) set, summarize it
        // in one batch, then filter/paginate in memory.
        $allRooms = $roomsQuery->orderBy('room_code')->get();
        $summaries = $this->utilization->summarizeRooms($allRooms);

        $filtered = $allRooms->filter(function (Room $room) use ($summaries, $quickFilter, $utilizationMin, $utilizationMax, $availabilityFilter) {
            $summary = $summaries[$room->id];

            if ($utilizationMin !== null && $summary['utilization_percent'] < (float) $utilizationMin) {
                return false;
            }
            if ($utilizationMax !== null && $summary['utilization_percent'] > (float) $utilizationMax) {
                return false;
            }
            if ($availabilityFilter !== '' && $summary['availability'] !== $availabilityFilter) {
                return false;
            }

            return match ($quickFilter) {
                'available' => $summary['availability'] === 'Available',
                'fully_booked' => $summary['availability'] === 'Fully Booked',
                'high_usage' => $summary['utilization_status'] === 'High Usage',
                'conflicts' => $summary['has_conflict'],
                'inactive' => $room->status !== 'Active',
                default => true,
            };
        })->values();

        $page = max(1, (int) $request->query('room_page', 1));
        $perPage = 10;
        $paged = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        $rooms = new \Illuminate\Pagination\LengthAwarePaginator(
            $paged,
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'room_page', 'query' => $request->query()]
        );

        $rooms->getCollection()->transform(function (Room $room) use ($summaries) {
            $room->setAttribute('utilization', $summaries[$room->id]);

            return $room;
        });

        return Inertia::render('Scheduling/Rooms/Index', [
            'rooms' => $rooms,
            'summary' => $this->usageSummary($allRooms, $summaries),
            'filters' => [
                'room_search' => $search,
                'quick_filter' => $quickFilter,
                'building' => $request->query('building', ''),
                'floor' => $request->query('floor', ''),
                'room_type' => $request->query('room_type', ''),
                'college_id' => $request->query('college_id', ''),
                'department_id' => $request->query('department_id', ''),
                'status' => $request->query('status', ''),
                'utilization_min' => $utilizationMin,
                'utilization_max' => $utilizationMax,
                'availability' => $availabilityFilter,
            ],
            'roomTypes' => StoreRoomRequest::ROOM_TYPES,
            'roomCategories' => RoomCategories::LIST,
            'buildings' => Room::query()->whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
            'floors' => Room::query()->whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor'),
            'departments' => Department::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name', 'college_id']),
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Room Schedule Details — weekly timetable + utilization breakdown
     * for one Room, powering the Rooms page's schedule modal.
     */
    public function schedule(Request $request, Room $room): JsonResponse
    {
        $room->load(['department', 'college']);

        return response()->json([
            'room' => $room,
            'summary' => $this->utilization->summarizeRoom($room),
            'timetable' => $this->utilization->timetableForRoom($room),
        ]);
    }

    /**
     * Store a newly created room in the Room Master.
     */
    public function store(StoreRoomRequest $request): RedirectResponse
    {
        $this->authorize('create', Room::class);

        Room::create($request->validated());

        return redirect()->route('scheduling.rooms')->with('success', 'Room added successfully.');
    }

    /**
     * Update an existing room in the Room Master.
     *
     * Room administration (capacity, type, College/Department
     * restriction) remains an Admin/Registrar responsibility (spec
     * Section 7, 16) — Assistant Dean and Dean/OIC may view and use
     * rooms for scheduling, but never edit the Room Master record.
     */
    public function update(UpdateRoomRequest $request, Room $room): RedirectResponse
    {
        $this->authorize('update', $room);

        $room->update($request->validated());

        return redirect()->route('scheduling.rooms')->with('success', 'Room updated successfully.');
    }

    /**
     * Delete a room from the Room Master.
     *
     * Double confirmation, mirroring FacultyController::destroy(): a
     * Room tied to a finalized/locked Section can never be deleted
     * outright (unlock and reassign first). A Room with active
     * (non-finalized) placements may still be deleted, but only after
     * the admin/registrar confirms the warning — deleting it leaves
     * those scheduled SectionSubject rows without a Room.
     */
    public function destroy(Request $request, Room $room): RedirectResponse
    {
        $this->authorize('delete', $room);

        $impact = $this->utilization->deletionImpact($room);

        if ($impact['has_finalized_assignment']) {
            return back()->with('error', 'This room is assigned to a finalized schedule ('.implode(', ', $impact['finalized_section_codes']).'). Unlock the affected section(s) and reassign before deleting.');
        }

        if ($impact['has_active_assignments'] && ! $request->boolean('confirmed')) {
            return back()->with('error', 'This room has active scheduled classes. Confirm the warning to proceed.')
                ->with('roomDeletionImpact', $impact);
        }

        $room->delete();

        return redirect()->route('scheduling.rooms')->with('success', 'Room deleted successfully.');
    }

    /**
     * Restrict which Rooms a non-unscoped role may see, matching the
     * scope rule already used for Faculty (no College = shared
     * General Education/Minor resource, visible to everyone):
     *
     *   - Administrator / Registrar: every Room.
     *   - Dean / OIC: Rooms with no College (shared) plus Rooms
     *     belonging to their own College.
     *   - Assistant Dean / Faculty: only shared (no-College) Rooms —
     *     the General Education/Minor scope.
     */
    private function applyScope($query, Request $request): void
    {
        $user = Auth::user();
        $role = $user?->getRoleNames()->first();

        if (! $user || in_array($role, self::UNSCOPED_ROLES, true)) {
            return;
        }

        if (in_array($role, ['Dean', 'OIC'], true) && $user->college_id) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('college_id')->orWhere('college_id', $user->college_id);
            });

            return;
        }

        $query->whereNull('college_id');
    }

    /**
     * Room Usage Summary cards shown above the Rooms table.
     */
    private function usageSummary($rooms, array $summaries): array
    {
        $active = $rooms->where('status', 'Active');
        $avgUtilization = $active->isNotEmpty()
            ? round($active->avg(fn (Room $room) => $summaries[$room->id]['utilization_percent']), 1)
            : 0.0;

        return [
            'total_rooms' => $rooms->count(),
            'active_rooms' => $active->count(),
            'available_rooms' => $rooms->filter(fn (Room $r) => $summaries[$r->id]['availability'] === 'Available')->count(),
            'fully_booked_rooms' => $rooms->filter(fn (Room $r) => $summaries[$r->id]['availability'] === 'Fully Booked')->count(),
            'average_utilization' => $avgUtilization,
            'rooms_with_conflicts' => $rooms->filter(fn (Room $r) => $summaries[$r->id]['has_conflict'])->count(),
        ];
    }
}