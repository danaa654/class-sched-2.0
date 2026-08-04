<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMajorRequest;
use App\Http\Requests\UpdateMajorRequest;
use App\Models\Major;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MajorController extends Controller
{
    /**
     * Display majors (paginated + searchable).
     *
     * Not currently bound to a route: the Academic Structure page renders
     * Colleges, Departments, and Majors together in a single Inertia visit,
     * owned by AcademicStructureController@index, so this page doesn't need
     * its own dedicated URL right now. Kept here — fully working — as the
     * natural place this query lives, and ready to wire to a route directly
     * later if Majors ever needs a standalone view.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('major_search', ''));

        $majors = Major::query()
            ->withTrashed()
            ->with([
                'department' => fn ($query) => $query->withTrashed()
                    ->with(['college' => fn ($collegeQuery) => $collegeQuery->withTrashed()]),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhereHas('department', function ($departmentQuery) use ($search) {
                            $departmentQuery->withTrashed()->where('name', 'like', "%{$search}%")
                                ->orWhereHas('college', function ($collegeQuery) use ($search) {
                                    $collegeQuery->withTrashed()->where('name', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderBy('name')
            ->paginate(10, ['*'], 'major_page')
            ->withQueryString();

        return Inertia::render('AcademicStructure/Index', [
            'majors' => $majors,
            'filters' => ['major_search' => $search],
        ]);
    }

    /**
     * Store a newly created major.
     */
    public function store(StoreMajorRequest $request): RedirectResponse
    {
        Major::create($request->validated());

        return redirect()->route('academic-structure')->with('success', 'Major created successfully.');
    }

    /**
     * Update the specified major.
     */
    public function update(UpdateMajorRequest $request, Major $major): RedirectResponse
    {
        $major->update($request->validated());

        return redirect()->route('academic-structure')->with('success', 'Major updated successfully.');
    }

    /**
     * Soft delete the specified major.
     */
    public function destroy(Major $major): RedirectResponse
    {
        $major->delete();

        return redirect()->route('academic-structure')->with('success', 'Major deleted successfully.');
    }

    /**
     * Restore a soft-deleted major.
     */
    public function restore(int $major): RedirectResponse
    {
        $record = Major::onlyTrashed()->findOrFail($major);
        $record->restore();

        return redirect()->route('academic-structure')->with('success', 'Major restored successfully.');
    }
}