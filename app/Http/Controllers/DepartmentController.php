<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     */
    public function destroy(Department $department): RedirectResponse
    {
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
}