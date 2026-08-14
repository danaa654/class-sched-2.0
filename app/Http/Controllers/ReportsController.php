<?php

namespace App\Http\Controllers;

use App\Services\ReportsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __construct(private readonly ReportsService $reports) {}

    /**
     * Display the Reports page. This is a read-only page: it only
     * ever queries existing data through ReportsService, never
     * mutates anything.
     */
    public function index(Request $request): Response
    {
        $this->authorize('view-reports');

        $user = $request->user();

        $filters = [
            'academic_year' => $request->query('academic_year', ''),
            'semester' => $request->query('semester', ''),
            // NEVER trust an arbitrary college_id filter from a
            // College-scoped Dean/OIC — pin it to their own College
            // regardless of what the request asked for (spec Section
            // 19, 20, 23). Admin/Registrar/Assistant Dean may filter
            // freely (Assistant Dean's report queries are further
            // restricted to GenEd/Minor data inside ReportsService).
            'college_id' => \App\Support\AccessScope::isCollegeScoped($user)
                ? (string) \App\Support\AccessScope::collegeId($user)
                : $request->query('college_id', ''),
            'major_id' => $request->query('major_id', ''),
            'year_level' => $request->query('year_level', ''),
            'section_id' => $request->query('section_id', ''),
            'section_type' => $request->query('section_type', ''),
            'faculty_id' => $request->query('faculty_id', ''),
            'room_id' => $request->query('room_id', ''),
        ];

        $reportType = (string) $request->query('report_type', '');

        $cleanFilters = array_filter($filters, fn ($v) => $v !== '' && $v !== null);

        return Inertia::render('Reports/Index', [
            'filterOptions' => $this->reports->filterOptions(),
            'filters' => $filters,
            'reportType' => $reportType,
            'summary' => $this->reports->dashboardSummary($filters['academic_year'] ?: null, $filters['semester'] ?: null),
            'report' => $reportType !== '' ? $this->reports->generate($reportType, $cleanFilters) : null,
            'generatedAt' => now()->toDateTimeString(),
        ]);
    }
}