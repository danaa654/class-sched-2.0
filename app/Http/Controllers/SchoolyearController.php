<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolYearRequest;
use App\Http\Requests\UpdateSchoolYearRequest;
use App\Models\SchoolYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolYearController extends Controller
{
    /**
     * Display school years (paginated + searchable).
     *
     * Not currently bound to a route: the Academic Calendar page renders
     * School Years, Semesters, and Academic Terms together in a single
     * Inertia visit, owned by AcademicCalendarController@index, so this
     * page doesn't need its own dedicated URL right now. Kept here — fully
     * working — as the natural place this query lives, and ready to wire
     * to a route directly later if School Years ever needs a standalone
     * view.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('school_year_search', ''));

        $schoolYears = SchoolYear::query()
            ->withTrashed()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('start_year', 'like', "%{$search}%")
                        ->orWhere('end_year', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('start_year')
            ->paginate(10, ['*'], 'school_year_page')
            ->withQueryString();

        return Inertia::render('AcademicCalendar/Index', [
            'schoolYears' => $schoolYears,
            'filters' => ['school_year_search' => $search],
        ]);
    }

    /**
     * Store a newly created school year.
     *
     * The name is always derived from start_year/end_year — never taken
     * from user input — per the "name must automatically be generated"
     * rule. The single-Active-record rule is enforced in the SchoolYear
     * model's `saved` hook, not here.
     */
    public function store(StoreSchoolYearRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        SchoolYear::create([
            'name' => "{$validated['start_year']}-{$validated['end_year']}",
            'start_year' => $validated['start_year'],
            'end_year' => $validated['end_year'],
            'status' => $validated['status'],
        ]);

        return redirect()->route('academic-calendar')->with('success', 'School year created successfully.');
    }

    /**
     * Update the specified school year.
     */
    public function update(UpdateSchoolYearRequest $request, SchoolYear $schoolYear): RedirectResponse
    {
        $validated = $request->validated();

        $schoolYear->update([
            'name' => "{$validated['start_year']}-{$validated['end_year']}",
            'start_year' => $validated['start_year'],
            'end_year' => $validated['end_year'],
            'status' => $validated['status'],
        ]);

        return redirect()->route('academic-calendar')->with('success', 'School year updated successfully.');
    }

    /**
     * Soft delete the specified school year.
     */
    public function destroy(SchoolYear $schoolYear): RedirectResponse
    {
        $schoolYear->delete();

        return redirect()->route('academic-calendar')->with('success', 'School year deleted successfully.');
    }

    /**
     * Restore a soft-deleted school year.
     */
    public function restore(int $schoolYear): RedirectResponse
    {
        $record = SchoolYear::onlyTrashed()->findOrFail($schoolYear);
        $record->restore();

        return redirect()->route('academic-calendar')->with('success', 'School year restored successfully.');
    }
}