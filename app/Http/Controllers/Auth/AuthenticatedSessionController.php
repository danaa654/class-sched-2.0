<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        // Same reasoning as PasswordResetLinkController::create() — the
        // app's shared Inertia props don't forward a generic "status"
        // session value, so we pass it explicitly. This is what shows
        // the "Your password has been reset." banner after
        // NewPasswordController redirects here.
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // NOTE: We intentionally do NOT use redirect()->intended() here.
        // intended() sends the user back to whatever URL was requested
        // right before they landed on /login. If that stored URL is stale
        // (an old route from a previous version of the app, an asset URL,
        // etc.) it can point at something that no longer exists, which
        // throws a real 404 right after a successful login. Always sending
        // the user to the dashboard is predictable and avoids that class
        // of bug entirely.
        $request->session()->forget('url.intended');

        return redirect()->route('dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}