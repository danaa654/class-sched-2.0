<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
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

        // Academic Term default — mirrors SectionController@index: on a
        // truly blank first visit (no academic_year/semester/term in
        // the querystring at all) default the Academic Year + Semester
        // filters to the Active Academic Term, so Reports opens scoped
        // to "this term" like the rest of Scheduling rather than mixing
        // every term's data by default. Falls back to no default (every
        // Section) when no term is Active, same as Sections.
        //
        // Distinguishing "no params sent" from "params explicitly sent
        // as empty" (e.g. the Reset button, which now always sends
        // term=all) is what lets Reset genuinely clear back to "All
        // Years" instead of re-defaulting to the Active term.
        $termGiven = $request->has('term') || $request->has('academic_year') || $request->has('semester');

        $defaultAcademicYear = '';
        $defaultSemester = '';

        if (! $termGiven) {
            $activeTerm = AcademicTerm::active();

            if ($activeTerm) {
                $activeTerm->loadMissing('schoolYear:id,name');
                $activeSemesterValue = $activeTerm->sectionSemesterValue();

                if ($activeTerm->schoolYear && $activeSemesterValue) {
                    $defaultAcademicYear = $activeTerm->schoolYear->name;
                    $defaultSemester = $activeSemesterValue;
                }
            }
        }

        // The `term` querystring param itself (the combined dropdown's
        // value) is only ever used to derive academic_year/semester on
        // the frontend before the request is sent — see
        // Reports/Index.vue's onTermChange(). The backend doesn't need
        // to decode it separately; academic_year/semester below already
        // carry whatever the Term dropdown resolved to.
        $filters = [
            'academic_year' => $request->query('academic_year', $defaultAcademicYear),
            'semester' => $request->query('semester', $defaultSemester),
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
            'filters' => array_merge($filters, [
                'term' => $this->resolveTermValue($filters['academic_year'], $filters['semester']),
            ]),
            'termOptions' => $this->termFilterOptions(),
            'reportType' => $reportType,
            'summary' => $this->reports->dashboardSummary($filters['academic_year'] ?: null, $filters['semester'] ?: null),
            'report' => $reportType !== '' ? $this->reports->generate($reportType, $cleanFilters) : null,
            'generatedAt' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Build the Reports page's Academic Term quick-filter dropdown.
     * Deliberately mirrors SectionController@termFilterOptions()
     * exactly (same value shape, same "All Terms" leading option, same
     * Archived tagging) so the two pages behave consistently — see
     * that method's docblock for the full rationale.
     *
     * @return list<array{value: string, label: string, status: string|null}>
     */
    private function termFilterOptions(): array
    {
        $options = [
            ['value' => 'all', 'label' => 'All Terms', 'status' => null],
        ];

        AcademicTerm::query()
            ->with(['schoolYear:id,name', 'semester:id,name'])
            ->orderByDesc('id')
            ->get()
            ->each(function (AcademicTerm $term) use (&$options) {
                $semesterValue = $term->sectionSemesterValue();

                if (! $term->schoolYear || ! $semesterValue) {
                    return;
                }

                $options[] = [
                    'value' => "{$term->schoolYear->name}|{$semesterValue}",
                    'label' => "{$term->schoolYear->name} · {$term->semester->name}",
                    'status' => $term->status,
                ];
            });

        return $options;
    }

    /**
     * The Term dropdown's current value, derived from whatever
     * academic_year/semester ended up selected — "all" when either is
     * blank (no single term is selected) or doesn't match a real term.
     */
    private function resolveTermValue(string $academicYear, string $semester): string
    {
        if ($academicYear === '' || $semester === '') {
            return 'all';
        }

        return "{$academicYear}|{$semester}";
    }
}