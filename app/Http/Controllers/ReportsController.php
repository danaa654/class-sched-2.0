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
        $filters = [
            'academic_year' => $request->query('academic_year', ''),
            'semester' => $request->query('semester', ''),
            'college_id' => $request->query('college_id', ''),
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