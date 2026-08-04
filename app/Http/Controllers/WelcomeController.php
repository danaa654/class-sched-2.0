<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Display the CLASSLY welcome / landing page.
     */
    public function index(): Response
    {
        return Inertia::render('Welcome');
    }
}