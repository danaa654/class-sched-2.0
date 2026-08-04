<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\SchoolYear;
use App\Models\Semester;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicCalendarController extends Controller
{
    /**
     * Display the Academic Calendar page (School Years, Semesters,
     * Academic Terms tabs).
     *
     * Owns the /academic-calendar route, so it's responsible for loading
     * all data the page needs. School Years, Semesters, and Academic
     * Terms each have an equivalent query on their own controller's
     * index() (unrouted) — this is where those queries actually get run
     * and passed as props.
     */
    public function index(Request $request): Response
    {
        $schoolYearSearch = trim((string) $request->query('school_year_search', ''));

        $schoolYears = SchoolYear::query()
            ->withTrashed()
            ->when($schoolYearSearch !== '', function ($query) use ($schoolYearSearch) {
                $query->where(function ($inner) use ($schoolYearSearch) {
                    $inner->where('name', 'like', "%{$schoolYearSearch}%")
                        ->orWhere('start_year', 'like', "%{$schoolYearSearch}%")
                        ->orWhere('end_year', 'like', "%{$schoolYearSearch}%");
                });
            })
            ->orderByDesc('start_year')
            ->paginate(10, ['*'], 'school_year_page')
            ->withQueryString();

        $semesterSearch = trim((string) $request->query('semester_search', ''));

        $semesters = Semester::query()
            ->withTrashed()
            ->when($semesterSearch !== '', function ($query) use ($semesterSearch) {
                $query->where(function ($inner) use ($semesterSearch) {
                    $inner->where('name', 'like', "%{$semesterSearch}%")
                        ->orWhere('short_name', 'like', "%{$semesterSearch}%");
                });
            })
            ->orderBy('display_order')
            ->paginate(10, ['*'], 'semester_page')
            ->withQueryString();

        $academicTermSearch = trim((string) $request->query('academic_term_search', ''));

        $academicTerms = AcademicTerm::query()
            ->withTrashed()
            ->with(['schoolYear', 'semester'])
            ->when($academicTermSearch !== '', function ($query) use ($academicTermSearch) {
                $query->where(function ($inner) use ($academicTermSearch) {
                    $inner->whereHas('schoolYear', function ($sy) use ($academicTermSearch) {
                        $sy->where('name', 'like', "%{$academicTermSearch}%");
                    })->orWhereHas('semester', function ($sem) use ($academicTermSearch) {
                        $sem->where('name', 'like', "%{$academicTermSearch}%")
                            ->orWhere('short_name', 'like', "%{$academicTermSearch}%");
                    });
                });
            })
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'academic_term_page')
            ->withQueryString();

        // Dropdown sources for the Academic Term Add/Edit dialog — only
        // Active School Years and Active Semesters are selectable.
        $activeSchoolYears = SchoolYear::query()
            ->where('status', 'Active')
            ->orderByDesc('start_year')
            ->get(['id', 'name']);

        $activeSemesters = Semester::query()
            ->where('status', 'Active')
            ->orderBy('display_order')
            ->get(['id', 'name', 'short_name']);

        return Inertia::render('AcademicCalendar/Index', [
            'schoolYears' => $schoolYears,
            'semesters' => $semesters,
            'academicTerms' => $academicTerms,
            'activeSchoolYears' => $activeSchoolYears,
            'activeSemesters' => $activeSemesters,
            'filters' => [
                'school_year_search' => $schoolYearSearch,
                'semester_search' => $semesterSearch,
                'academic_term_search' => $academicTermSearch,
            ],
        ]);
    }
}