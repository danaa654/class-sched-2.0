<?php

namespace App\Http\Middleware;

use App\Models\AcademicTerm;
use App\Models\Notification;
use App\Support\ViewingTerm;
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
                    // Section-level schedule finalization/unlock —
                    // Registrar/Admin only (SectionPolicy::finalize()/
                    // unlockSchedule()). Gates the Finalize/Unlock
                    // button in Sections/Index.vue; the real
                    // enforcement is still the Policy + Gate on every
                    // request, this is UI-visibility only.
                    'manageFinalization' => \App\Support\AccessScope::isUnrestricted($user),
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
            // Semester) — the real, system-wide one, unaffected by
            // any user's Viewing Term switch below. Kept for anything
            // that specifically needs the true Active term regardless
            // of what the current user is browsing.
            'activeAcademicTerm' => fn () => AcademicTerm::query()
                ->where('status', 'Active')
                ->with(['schoolYear:id,name', 'semester:id,name'])
                ->first(['id', 'school_year_id', 'semester_id', 'status']),
            // The Academic Term THIS user is currently viewing —
            // their session override (Admin/Registrar only — see
            // ViewingTerm) if one is set, else the real Active term.
            // This is what the header pill in AppLayout.vue displays
            // and what defaults Reports/Sections/etc. build against,
            // so switching it never affects any other user.
            'viewingAcademicTerm' => fn () => ViewingTerm::resolve($request)
                ?->loadMissing(['schoolYear:id,name', 'semester:id,name']),
            // True only when the user's session override points at a
            // genuinely different term than the real Active one — see
            // ViewingTerm::isDeviatingFromActive(). Lets the header
            // show a "Planning" badge exactly when it should, not
            // whenever an override happens to be set (an override
            // pointed AT the Active term itself must never show
            // "Planning").
            'isViewingOverride' => fn () => ViewingTerm::isDeviatingFromActive($request),
            // Whether this user is even allowed to use the switch
            // (Administrator/Registrar only) — gates showing the
            // dropdown affordance at all in the header.
            'canSwitchViewingTerm' => fn () => ViewingTerm::canSwitch($user),
            // Every switchable (non-Archived) Academic Term, for the
            // header dropdown. Only fetched for users who can actually
            // switch, to avoid the query on every request otherwise.
            'availableAcademicTerms' => fn () => ViewingTerm::canSwitch($user)
                ? AcademicTerm::query()
                    ->where('status', '!=', 'Archived')
                    ->with(['schoolYear:id,name', 'semester:id,name'])
                    ->orderByDesc('id')
                    ->get(['id', 'school_year_id', 'semester_id', 'status'])
                : [],
            // SCHEDULING NOTIFICATION SYSTEM — unread count for the
            // header bell badge (see NotificationBell.vue), shared on
            // every full Inertia visit so the badge is correct on
            // first paint before the poll kicks in. A closure so it's
            // never queried on a partial reload that doesn't need it.
            'unreadNotificationCount' => fn () => $user
                ? Notification::query()->where('recipient_user_id', $user->id)->where('is_read', false)->count()
                : 0,
        ];
    }
}