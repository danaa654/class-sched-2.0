<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Department;
use App\Models\Major;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicStructureController extends Controller
{
    /**
     * Display the Academic Structure page (Colleges + Departments + Majors tabs).
     *
     * All tabs paginate/search independently, so each uses its own
     * query-string keys (college_search/college_page,
     * department_search/department_page, major_search/major_page) to avoid
     * colliding with each other when Inertia does a partial reload of just
     * one tab.
     */
    public function index(Request $request): Response
    {
        $this->authorize('manage-academic-structure');

        $collegeSearch = trim((string) $request->query('college_search', ''));
        $departmentSearch = trim((string) $request->query('department_search', ''));
        $majorSearch = trim((string) $request->query('major_search', ''));

        $colleges = College::query()
            ->withTrashed()
            ->when($collegeSearch !== '', function ($query) use ($collegeSearch) {
                $query->where(function ($inner) use ($collegeSearch) {
                    $inner->where('code', 'like', "%{$collegeSearch}%")
                        ->orWhere('name', 'like', "%{$collegeSearch}%")
                        ->orWhere('short_name', 'like', "%{$collegeSearch}%");
                });
            })
            ->orderBy('name')
            ->paginate(10, ['*'], 'college_page')
            ->withQueryString();

        $departments = Department::query()
            ->withTrashed()
            // Eager-load the college even if it has since been soft-deleted,
            // so the department's College column still shows a name instead
            // of blank (colleges' own SoftDeletes global scope would
            // otherwise hide it).
            ->with(['college' => fn ($query) => $query->withTrashed()])
            ->when($departmentSearch !== '', function ($query) use ($departmentSearch) {
                $query->where(function ($inner) use ($departmentSearch) {
                    $inner->where('code', 'like', "%{$departmentSearch}%")
                        ->orWhere('name', 'like', "%{$departmentSearch}%")
                        ->orWhere('short_name', 'like', "%{$departmentSearch}%")
                        ->orWhereHas('college', function ($collegeQuery) use ($departmentSearch) {
                            $collegeQuery->withTrashed()->where('name', 'like', "%{$departmentSearch}%");
                        });
                });
            })
            ->orderBy('name')
            ->paginate(10, ['*'], 'department_page')
            ->withQueryString();

        $majors = Major::query()
            ->withTrashed()
            // Eager-load department + college even if since soft-deleted, so
            // the major's College/Department columns still show names
            // instead of blank (their SoftDeletes global scopes would
            // otherwise hide them).
            ->with([
                'department' => fn ($query) => $query->withTrashed()
                    ->with(['college' => fn ($collegeQuery) => $collegeQuery->withTrashed()]),
            ])
            ->when($majorSearch !== '', function ($query) use ($majorSearch) {
                $query->where(function ($inner) use ($majorSearch) {
                    $inner->where('code', 'like', "%{$majorSearch}%")
                        ->orWhere('name', 'like', "%{$majorSearch}%")
                        ->orWhereHas('department', function ($departmentQuery) use ($majorSearch) {
                            $departmentQuery->withTrashed()->where('name', 'like', "%{$majorSearch}%")
                                ->orWhereHas('college', function ($collegeQuery) use ($majorSearch) {
                                    $collegeQuery->withTrashed()->where('name', 'like', "%{$majorSearch}%");
                                });
                        });
                });
            })
            ->orderBy('name')
            ->paginate(10, ['*'], 'major_page')
            ->withQueryString();

        return Inertia::render('AcademicStructure/Index', [
            'colleges' => $colleges,
            'departments' => $departments,
            'majors' => $majors,
            // Active, non-deleted colleges only — used to populate the
            // College dropdown in the Department Add/Edit dialog, and as
            // the first cascading dropdown in the Major Add/Edit dialog.
            'activeColleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            // Active, non-deleted departments only — used to populate the
            // Department dropdown in the Major Add/Edit dialog. Includes
            // college_id so the frontend can filter by selected College
            // without a round trip.
            'activeDepartments' => Department::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'college_id', 'name']),
            'filters' => [
                'college_search' => $collegeSearch,
                'department_search' => $departmentSearch,
                'major_search' => $majorSearch,
            ],
        ]);
    }
}