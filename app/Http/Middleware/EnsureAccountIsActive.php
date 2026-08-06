<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every request from a logged-in user whose account has been set
 * to "Inactive" (via the Deactivate row action in User Management).
 *
 * Blocking at login alone isn't enough: an Administrator can deactivate
 * someone who is already mid-session, and that browser tab would keep
 * working until it hit a fresh Inertia visit. This middleware runs on
 * every web request, so a deactivated user is logged out and bounced to
 * the login page the moment their next request comes in.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user && $user->status === 'Inactive') {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Your account has been deactivated. Please contact your Administrator.');
        }

        return $next($request);
    }
}