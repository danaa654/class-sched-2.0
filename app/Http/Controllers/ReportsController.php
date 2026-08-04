<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    /**
     * Display the Reports page.
     */
    public function index(): Response
    {
        return Inertia::render('Reports/Index');
    }
}