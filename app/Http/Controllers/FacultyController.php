<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFacultyRequest;
use App\Http\Requests\UpdateFacultyRequest;
use App\Models\College;
use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Models\Subject;
use App\Services\FacultyWorkloadService;
use App\Services\NotificationService;
use App\Support\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FacultyController extends Controller
{
    public function __construct(
        private readonly FacultyWorkloadService $workloadService,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Display the Faculty Master page.
     *
     * Faculty members here are NOT system users — they never log in.
     * This is purely a roster the registrar/admin maintains so the
     * scheduling module has faculty to draw from later. No subject,
     * schedule, room, or section assignment happens here.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Faculty::class);

        $search = trim((string) $request->query('faculty_search', ''));
        $category = $request->query('faculty_category', '');
        $category = in_array($category, ['Department Faculty', 'General Education Faculty'], true) ? $category : '';

        $faculties = Faculty::query()
            ->visibleTo($request->user())
            ->with(['college' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('faculty_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereHas('college', function ($collegeQuery) use ($search) {
                            $collegeQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($category !== '', fn ($query) => $category === 'General Education Faculty'
                ? $query->whereNull('college_id')
                : $query->whereNotNull('college_id'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10, ['*'], 'faculty_page')
            ->withQueryString();

        // FACULTY WORKLOAD VALIDATION — "Dashboard Indicators". Each
        // row gets its real current/max/remaining load (Scheduled +
        // Draft placements, active semester only) and a 🟢/🟡/🔴
        // status so the roster doubles as an at-a-glance overload
        // report, computed via FacultyWorkloadService — the same
        // engine Auto Generate/Recommend/Manual Assignment/Save
        // Schedule use — so this can never disagree with those.
        $faculties->getCollection()->transform(function (Faculty $faculty) {
            $evaluation = $this->workloadService->evaluate($faculty);
            $faculty->setAttribute('workload', $evaluation);

            return $faculty;
        });

        return Inertia::render('Scheduling/Faculty/Index', [
            'faculties' => $faculties,
            'filters' => ['faculty_search' => $search, 'faculty_category' => $category],
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'nextFacultyId' => $this->nextFacultyId(),

            // Faculty Load Requests — moved here from its own page
            // (formerly FacultyLoadRequestController@index) so it
            // renders as a section on the Faculty page itself.
            ...$this->loadRequestsProps($request),
        ]);
    }

    /**
     * Props for the "Faculty Load Requests" section of the Faculty
     * page. Admin/Registrar see the full queue (for review); Dean/OIC/
     * Assistant Dean see only requests touching faculty in their own
     * scope, so they can track status of what they've submitted.
     */
    private function loadRequestsProps(Request $request): array
    {
        $user = $request->user();

        $loadRequests = FacultyLoadRequest::query()
            ->with(['faculty:id,faculty_id,first_name,last_name,college_id,max_teaching_units,max_weekly_hours', 'requestedBy:id,name', 'reviewedBy:id,name'])
            ->when(! AccessScope::isUnrestricted($user), function ($query) use ($user) {
                $query->whereHas('faculty', function ($facultyQuery) use ($user) {
                    if (AccessScope::isAssistantDean($user)) {
                        $facultyQuery->whereNull('college_id');

                        return;
                    }

                    $facultyQuery->where('college_id', $user->college_id);
                });
            });

        // Count BEFORE pagination — used for the reminder banner so it
        // reflects the whole scoped queue, not just whatever page of
        // the table happens to be loaded.
        $pendingCount = (clone $loadRequests)->where('status', 'Pending')->count();

        $loadRequests = $loadRequests
            ->latest()
            ->paginate(10, ['*'], 'load_requests_page')
            ->withQueryString();

        return [
            'loadRequests' => $loadRequests,
            'pendingLoadRequestsCount' => $pendingCount,
            'hardCapUnits' => FacultyLoadRequest::effectiveCapFor($user),
            // Faculty roster for the "New Request" dropdown, scoped the
            // same way the Faculty Master roster itself is — a Dean
            // can only request an increase for faculty they can
            // already see/manage.
            'loadRequestFaculties' => Faculty::query()
                ->visibleTo($user)
                ->where('status', 'Active')
                ->orderBy('last_name')
                ->get(['id', 'faculty_id', 'first_name', 'last_name', 'max_teaching_units', 'max_weekly_hours', 'workload_type']),
            'canReviewLoadRequests' => $user->can('review', FacultyLoadRequest::class),
        ];
    }

    /**
     * Display the Faculty Details page (Information, Teaching
     * Qualifications, and Workload tabs).
     */
    public function show(Faculty $faculty): Response
    {
        $this->authorize('view', $faculty);

        $faculty->load([
            'college' => fn ($query) => $query->withTrashed(),
            'subjects' => fn ($query) => $query->orderBy('subject_code'),
        ]);

        // Real assigned workload (Scheduled + Draft placements, active
        // semester only) — see FacultyController@index docblock above.
        // includePlacements: true here (and only here) so the Workload
        // tab can list *which* subjects/sections make up the load
        // figure, not just the summary numbers.
        $faculty->setAttribute('workload', $this->workloadService->evaluate($faculty, includePlacements: true));

        return Inertia::render('Scheduling/Faculty/Details', [
            'faculty' => $faculty,
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'subjects' => Subject::query()
                ->where('is_active', true)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title', 'category', 'units']),
        ]);
    }

    /**
     * Store a newly created faculty member in the Faculty Master.
     */
    public function store(StoreFacultyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // NEVER trust college_id from the payload (spec Section 23) —
        // it is already re-derived/validated in StoreFacultyRequest,
        // but the policy check here is the authoritative gate.
        $this->authorize('createForCollege', [Faculty::class, $data['college_id'] ?? null]);

        // Same rule as update(): only Admin/Registrar may set a load
        // ceiling above the system default when creating a new Faculty
        // record. Dean/OIC/Assistant Dean get the default regardless
        // of what they typed — they can submit a FacultyLoadRequest
        // afterward if this new hire genuinely needs a higher ceiling.
        if (! $request->user()->can('changeMaxLoad', Faculty::class)) {
            $data['max_teaching_units'] = 24;
            $data['max_weekly_hours'] = null;
            $data['workload_type'] = 'units';
        }

        Faculty::create($data);

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member added successfully.');
    }

    /**
     * Update an existing faculty member in the Faculty Master.
     */
    public function update(UpdateFacultyRequest $request, Faculty $faculty): RedirectResponse
    {
        $this->authorize('update', $faculty);

        $data = $request->validated();

        // Per spec Section 6/11: Dean/OIC/Assistant Dean may never
        // reassign a faculty member's College. Only Admin/Registrar
        // (already bypassed via Gate::before / isUnrestricted) may
        // change college_id; anyone else has it silently pinned back.
        if (array_key_exists('college_id', $data) && $data['college_id'] !== $faculty->college_id) {
            $this->authorize('reassignCollege', Faculty::class);
        }

        // Per the new Faculty Load Request workflow: Dean/OIC/
        // Assistant Dean have no direct write path to a faculty
        // member's load ceiling — only Admin/Registrar do (see
        // FacultyPolicy::changeMaxLoad()). Anyone else submitting this
        // form has those fields silently pinned back to their current
        // value, same pattern as college_id above. They must go
        // through FacultyLoadRequestController instead.
        if (! $request->user()->can('changeMaxLoad', Faculty::class)) {
            $data['max_teaching_units'] = $faculty->max_teaching_units;
            $data['max_weekly_hours'] = $faculty->max_weekly_hours;
            $data['workload_type'] = $faculty->workload_type;
        }

        // Capture before the write so we can tell whether the ceiling
        // actually moved — only notify on a real change, not on every
        // save of this form (e.g. editing the email shouldn't fire a
        // "load updated" notification).
        $oldMaxTeachingUnits = $faculty->max_teaching_units;

        $faculty->update($data);

        if (array_key_exists('max_teaching_units', $data) && $data['max_teaching_units'] !== $oldMaxTeachingUnits) {
            $this->notifications->facultyMaxLoadEditedDirectly(
                $faculty,
                $request->user(),
                $oldMaxTeachingUnits,
                $data['max_teaching_units'],
            );
        }

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member updated successfully.');
    }

    /**
     * Delete a faculty member from the Faculty Master.
     */
    public function destroy(Faculty $faculty): RedirectResponse
    {
        $this->authorize('delete', $faculty);

        $faculty->delete();

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member deleted successfully.');
    }

    /**
     * Determine the next sequential Faculty ID, e.g. FAC-2026-0001.
     *
     * This is only a suggestion pre-filled into the Add Faculty form —
     * the registrar/admin can still edit it freely before saving, and
     * uniqueness is always re-checked server-side on store.
     */
    private function nextFacultyId(): string
    {
        $year = now()->year;
        $prefix = "FAC-{$year}-";

        $lastId = Faculty::withTrashed()
            ->where('faculty_id', 'like', "{$prefix}%")
            ->orderByDesc('faculty_id')
            ->value('faculty_id');

        $nextNumber = $lastId
            ? ((int) substr($lastId, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}