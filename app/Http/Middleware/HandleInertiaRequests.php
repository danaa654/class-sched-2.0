<?php

namespace App\Http\Middleware;

use App\Models\AcademicTerm;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Role names for the logged-in user, shared globally so
                // any page/layout can gate UI (e.g. the admin-only
                // "Manage Account" tab on User Management) without each
                // controller having to remember to pass it separately.
                'roles' => fn () => $user?->getRoleNames() ?? [],
                // Coarse module-level abilities for SIDEBAR/BUTTON
                // visibility only. This is a UI convenience, never the
                // authorization boundary itself — every route/action
                // these gate is independently re-checked server-side
                // (Policies + Gate::define in AppServiceProvider) on
                // every request, so spoofing these client-side changes
                // nothing (spec Section 21/23).
                'can' => fn () => $user ? [
                    'manageUsers' => $user->can('manage-users'),
                    'manageAcademicStructure' => $user->can('manage-academic-structure'),
                    'manageAcademicCalendar' => $user->can('manage-academic-calendar'),
                    'manageCurriculum' => $user->can('manage-curriculum'),
                    'manageSettings' => $user->can('manage-settings'),
                    'viewReports' => $user->can('view-reports'),
                    'viewScheduling' => $user->can('view-scheduling'),
                    'runAutoSchedule' => $user->can('run-auto-schedule'),
                    'manageFaculty' => $user->can('create', \App\Models\Faculty::class),
                    'manageRooms' => $user->can('create', \App\Models\Room::class),
                    'manageSections' => $user->can('create', \App\Models\Section::class),
                ] : [],
                // The College a Dean/OIC is scoped to (null for
                // unrestricted/Assistant Dean roles). Lets the
                // frontend show "no College assigned" messaging (spec
                // Section 26) without re-deriving the role logic.
                'collegeId' => fn () => \App\Support\AccessScope::collegeId($user),
                'hasNoAssignedCollege' => fn () => \App\Support\AccessScope::hasNoAssignedCollege($user),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // The currently Active Academic Term (School Year +
            // Semester), shown in the top header on every page — see
            // AppLayout.vue. A closure so it's only queried when a
            // page actually renders (every full Inertia visit), not
            // added as dead weight to every partial reload.
            'activeAcademicTerm' => fn () => AcademicTerm::query()
                ->where('status', 'Active')
                ->with(['schoolYear:id,name', 'semester:id,name'])
                ->first(['id', 'school_year_id', 'semester_id', 'status']),
        ];
    }
}