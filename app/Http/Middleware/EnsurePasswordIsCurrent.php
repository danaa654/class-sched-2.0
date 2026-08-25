<?php

namespace App\Http\Middleware;

use App\Services\PasswordPolicyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY / PASSWORD POLICY — runs on every authenticated web
 * request (registered in bootstrap/app.php alongside
 * EnsureAccountIsActive) and forces a password change when either:
 *
 * - an Administrator has flipped must_change_password on the user
 *   (the "require password change on next login" toggle in User
 *   Management), or
 * - the user's password_changed_at is older than
 *   security.password_expiry_days (0 = expiry disabled).
 *
 * Unlike EnsureAccountIsActive (which logs the user out), this
 * redirects to a change-password screen instead — the person should
 * be able to fix an expired/flagged password immediately, in the
 * same session, not be locked out entirely. The change-password
 * route itself (and logout) must stay excluded below, or the forced
 * redirect would loop against itself.
 *
 * PasswordPolicyService is injected via the CONSTRUCTOR, not as a
 * handle() parameter. Laravel only resolves extra handle() parameters
 * for route middleware invoked with a ":arg" suffix (e.g. 'auth:web')
 * — global/group middleware (registered as a plain class string in
 * bootstrap/app.php) is always called as handle($request, $next), so
 * a third typed parameter there throws ArgumentCountError.
 */
class EnsurePasswordIsCurrent
{
    /**
     * Route names this middleware must never redirect away from,
     * or a user who needs to change their password could never
     * reach the page that lets them do it.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'password.change',
        'password.change.update',
        'logout',
    ];

    public function __construct(private readonly PasswordPolicyService $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        $mustChange = $user->must_change_password
            || $this->policy->isExpired($user->password_changed_at);

        if ($mustChange && ! $request->expectsJson()) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}