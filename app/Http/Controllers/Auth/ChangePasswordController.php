<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\PasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SECURITY / PASSWORD POLICY — the full-page screen
 * EnsurePasswordIsCurrent redirects to when a user's password is
 * flagged (must_change_password) or expired
 * (security.password_expiry_days). A full-page redirect rather than
 * a blocking modal, matching how EnsureAccountIsActive already
 * handles a forced state change (full redirect to /login) elsewhere
 * in this app.
 *
 * Deliberately separate from NewPasswordController (the "forgot
 * password" email-link flow) and from
 * UsersController::updateAccount() (voluntary self-service edits) —
 * this one is reachable only while must_change_password/expiry is
 * actually true, requires the CURRENT password to confirm identity
 * (the user is already authenticated, so no email token is needed),
 * and is the only place that clears both flags together.
 */
class ChangePasswordController extends Controller
{
    public function __construct(
        private readonly PasswordPolicyService $policy,
        private readonly ActivityLogService $activityLog = new ActivityLogService,
    ) {}

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Auth/ChangePassword', [
            'reason' => $user->must_change_password ? 'required' : 'expired',
            'policyDescription' => $this->policy->description(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', ...$this->policy->rules()],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        $this->activityLog->record(
            ActivityLogService::PASSWORD_CHANGED,
            "{$user->full_name} changed their password.",
            $user,
            $user,
        );

        return redirect()->route('dashboard')->with('success', 'Your password has been updated.');
    }
}