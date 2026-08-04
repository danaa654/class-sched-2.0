<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SchedulingController extends Controller
{
    /**
     * Display the Scheduling page.
     */
    public function index(): Response
    {
        return Inertia::render('Scheduling/Index');
    }
}