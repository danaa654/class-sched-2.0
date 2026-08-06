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
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
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