<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSemesterRequest;
use App\Http\Requests\UpdateSemesterRequest;
use App\Models\Semester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SemesterController extends Controller
{
    /**
     * Display semesters (paginated + searchable).
     *
     * Not bound to its own route: like School Years, the Academic
     * Calendar page renders School Years, Semesters, and Academic Terms
     * together in a single Inertia visit, owned by
     * AcademicCalendarController@index. Kept here as the natural place
     * this query lives, and reused directly by that controller.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('semester_search', ''));

        $semesters = Semester::query()
            ->withTrashed()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('display_order')
            ->paginate(10, ['*'], 'semester_page')
            ->withQueryString();

        return Inertia::render('AcademicCalendar/Index', [
            'semesters' => $semesters,
            'filters' => ['semester_search' => $search],
        ]);
    }

    /**
     * Store a newly created semester.
     */
    public function store(StoreSemesterRequest $request): RedirectResponse
    {
        Semester::create($request->validated());

        return redirect()->route('academic-calendar')->with('success', 'Semester created successfully.');
    }

    /**
     * Update the specified semester.
     */
    public function update(UpdateSemesterRequest $request, Semester $semester): RedirectResponse
    {
        $semester->update($request->validated());

        return redirect()->route('academic-calendar')->with('success', 'Semester updated successfully.');
    }

    /**
     * Soft delete the specified semester.
     */
    public function destroy(Semester $semester): RedirectResponse
    {
        $semester->delete();

        return redirect()->route('academic-calendar')->with('success', 'Semester deleted successfully.');
    }

    /**
     * Restore a soft-deleted semester.
     */
    public function restore(int $semester): RedirectResponse
    {
        $record = Semester::onlyTrashed()->findOrFail($semester);
        $record->restore();

        return redirect()->route('academic-calendar')->with('success', 'Semester restored successfully.');
    }
}