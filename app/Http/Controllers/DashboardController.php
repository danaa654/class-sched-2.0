<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the authenticated dashboard.
     */
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'roles' => auth()->user()->getRoleNames(),
        ]);
    }
}