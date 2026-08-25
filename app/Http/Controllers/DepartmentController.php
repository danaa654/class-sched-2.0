<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use App\Models\Major;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Display departments (paginated + searchable).
     *
     * Not currently bound to a route: the Academic Structure page renders
     * Colleges and Departments together in a single Inertia visit, owned
     * by AcademicStructureController@index, so this page doesn't need its
     * own dedicated URL right now. Kept here — fully working — as the
     * natural place this query lives, and ready to wire to a route
     * directly later if Departments ever needs a standalone view.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('department_search', ''));

        $departments = Department::query()
            ->withTrashed()
            ->with(['college' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('short_name', 'like', "%{$search}%")
                        ->orWhereHas('college', function ($collegeQuery) use ($search) {
                            $collegeQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('name')
            ->paginate(10, ['*'], 'department_page')
            ->withQueryString();

        return Inertia::render('AcademicStructure/Index', [
            'departments' => $departments,
            'filters' => ['department_search' => $search],
        ]);
    }

    /**
     * Store a newly created department.
     */
    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        Department::create($request->validated());

        return redirect()->route('academic-structure')->with('success', 'Department created successfully.');
    }

    /**
     * Update the specified department.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        return redirect()->route('academic-structure')->with('success', 'Department updated successfully.');
    }

    /**
     * Soft delete the specified department.
     *
     * DELETE GATE — same reasoning as MajorController::destroy():
     * blocked while any active (non-trashed) Major, Room, or User
     * still belongs to this Department, so the Registrar gets a
     * clear message instead of either a silent orphaning or a raw DB
     * constraint error (majors.department_id cascadeOnDelete only
     * fires on a real row delete, never on this soft delete, so
     * nothing is actually at risk of being destroyed here — this
     * check exists purely to stop archiving a Department that's
     * still in active use).
     */
    public function destroy(Department $department): RedirectResponse
    {
        $blockers = [];

        $majorCount = Major::query()->where('department_id', $department->id)->count();
        if ($majorCount > 0) {
            $blockers[] = $majorCount === 1 ? '1 major' : "{$majorCount} majors";
        }

        $roomCount = Room::query()->where('department_id', $department->id)->count();
        if ($roomCount > 0) {
            $blockers[] = $roomCount === 1 ? '1 room' : "{$roomCount} rooms";
        }

        $userCount = User::query()->where('department_id', $department->id)->count();
        if ($userCount > 0) {
            $blockers[] = $userCount === 1 ? '1 user' : "{$userCount} users";
        }

        if (! empty($blockers)) {
            throw ValidationException::withMessages([
                'code' => "This department can't be deleted — it still has ".implode(', ', $blockers).' attached to it. Remove or reassign those first.',
            ]);
        }

        $department->delete();

        return redirect()->route('academic-structure')->with('success', 'Department deleted successfully.');
    }

    /**
     * Restore a soft-deleted department.
     */
    public function restore(int $department): RedirectResponse
    {
        $record = Department::onlyTrashed()->findOrFail($department);
        $record->restore();

        return redirect()->route('academic-structure')->with('success', 'Department restored successfully.');
    }

    /**
     * Permanently delete an already-soft-deleted department — the "clean
     * it up for good" action for rows already sitting in the Deleted
     * state. Same gate as the rest of Academic Structure. Only reachable
     * for a row that's already trashed; a still-active department must
     * go through destroy() first.
     *
     * Re-runs the same attachment check as destroy(): something could
     * have been re-pointed at this department's id after it was soft
     * deleted, and a permanent delete can't be undone the way the soft
     * delete could.
     */
    public function forceDelete(Request $request, int $department): RedirectResponse
    {
        $this->authorize('manage-academic-structure');

        $record = Department::onlyTrashed()->findOrFail($department);

        $blockers = [];

        $majorCount = Major::query()->where('department_id', $record->id)->count();
        if ($majorCount > 0) {
            $blockers[] = $majorCount === 1 ? '1 major' : "{$majorCount} majors";
        }

        $roomCount = Room::query()->where('department_id', $record->id)->count();
        if ($roomCount > 0) {
            $blockers[] = $roomCount === 1 ? '1 room' : "{$roomCount} rooms";
        }

        $userCount = User::query()->where('department_id', $record->id)->count();
        if ($userCount > 0) {
            $blockers[] = $userCount === 1 ? '1 user' : "{$userCount} users";
        }

        if (! empty($blockers)) {
            throw ValidationException::withMessages([
                'code' => "This department can't be permanently deleted — it still has ".implode(', ', $blockers).' attached to it. Remove or reassign those first.',
            ]);
        }

        $name = $record->name;
        $record->forceDelete();

        return redirect()->route('academic-structure')->with('success', "{$name} was permanently deleted.");
    }
}