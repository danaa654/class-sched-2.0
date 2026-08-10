<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(Request $request): Response
    {
        // This app's HandleInertiaRequests middleware only shares
        // flash.success/flash.error globally — it does not forward a
        // generic "status" session value. We pass it explicitly here so
        // the ForgotPassword page's `status` prop is actually populated
        // after a redirect back from store().
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|string|email',
        ]);

        // We don't reveal whether the email exists — same response either way.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        // Still route back with a generic "check your inbox" style status so
        // the login screen can't be used to enumerate registered accounts.
        if ($status === Password::INVALID_USER) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}