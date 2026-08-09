<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
    }

    /**
     * Display the authenticated Dashboard — the Scheduling Control
     * Center. All widget data is pre-scoped to the logged-in user's
     * role/College by DashboardService, so the page component never
     * has to re-derive visibility rules on the frontend.
     */
    public function index(): Response
    {
        $user = auth()->user();

        return Inertia::render('Dashboard', [
            'roles' => $user->getRoleNames(),
            'overview' => $this->dashboardService->overview($user),
        ]);
    }
}