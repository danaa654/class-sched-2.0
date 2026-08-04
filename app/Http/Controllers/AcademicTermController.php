<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAcademicTermRequest;
use App\Http\Requests\UpdateAcademicTermRequest;
use App\Models\AcademicTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicTermController extends Controller
{
    /**
     * Display academic terms (paginated + searchable).
     *
     * Not bound to its own route: like School Years and Semesters, the
     * Academic Calendar page renders School Years, Semesters, and
     * Academic Terms together in a single Inertia visit, owned by
     * AcademicCalendarController@index. Kept here as the natural place
     * this query lives, and reused directly by that controller.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('academic_term_search', ''));

        $academicTerms = AcademicTerm::query()
            ->withTrashed()
            ->with(['schoolYear', 'semester'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->whereHas('schoolYear', function ($sy) use ($search) {
                        $sy->where('name', 'like', "%{$search}%");
                    })->orWhereHas('semester', function ($sem) use ($search) {
                        $sem->where('name', 'like', "%{$search}%")
                            ->orWhere('short_name', 'like', "%{$search}%");
                    });
                });
            })
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'academic_term_page')
            ->withQueryString();

        return Inertia::render('AcademicCalendar/Index', [
            'academicTerms' => $academicTerms,
            'filters' => ['academic_term_search' => $search],
        ]);
    }

    /**
     * Store a newly created academic term.
     *
     * The single-Active-record rule is enforced in the AcademicTerm
     * model's `saved` hook, not here.
     */
    public function store(StoreAcademicTermRequest $request): RedirectResponse
    {
        AcademicTerm::create($request->validated());

        return redirect()->route('academic-calendar')->with('success', 'Academic term created successfully.');
    }

    /**
     * Update the specified academic term.
     */
    public function update(UpdateAcademicTermRequest $request, AcademicTerm $academicTerm): RedirectResponse
    {
        $academicTerm->update($request->validated());

        return redirect()->route('academic-calendar')->with('success', 'Academic term updated successfully.');
    }

    /**
     * Soft delete the specified academic term.
     */
    public function destroy(AcademicTerm $academicTerm): RedirectResponse
    {
        $academicTerm->delete();

        return redirect()->route('academic-calendar')->with('success', 'Academic term deleted successfully.');
    }

    /**
     * Restore a soft-deleted academic term.
     */
    public function restore(int $academicTerm): RedirectResponse
    {
        $record = AcademicTerm::onlyTrashed()->findOrFail($academicTerm);
        $record->restore();

        return redirect()->route('academic-calendar')->with('success', 'Academic term restored successfully.');
    }
}