<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Display the Settings page.
     */
    public function index(): Response
    {
        return Inertia::render('Settings/Index');
    }
}