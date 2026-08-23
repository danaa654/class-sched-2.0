<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ACTIVE SESSIONS — Administrator-only visibility into who is
 * currently logged in to Classly, backed directly by the `sessions`
 * table (requires SESSION_DRIVER=database, which this app already
 * uses). Read-only except for a single, explicit "force logout"
 * action that deletes one session row.
 *
 * The session list itself is rendered as a tab on the Settings page
 * (see SettingsController::index(), which calls
 * ActiveSessionController::activeSessions() to build the
 * 'activeSessions' prop) rather than as its own page — this
 * controller only owns the data-gathering and the destroy action.
 *
 * This never touches authentication itself — a forced-out user is
 * simply treated like anyone whose session expired: their next
 * request finds no matching session and gets redirected to log in
 * again. No account is locked, disabled, or modified.
 */
class ActiveSessionController extends Controller
{
    /**
     * Build the grouped "who's logged in" list consumed by the
     * Settings/Index page's Active Sessions tab.
     *
     * @return list<array{user_id: int, name: string, role: string, sessions: array}>
     */
    public static function activeSessions(Request $request): array
    {
        $lifetimeMinutes = (int) config('session.lifetime', 120);
        $cutoff = now()->subMinutes($lifetimeMinutes)->getTimestamp();

        $rows = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $cutoff)
            ->orderByDesc('last_activity')
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->unique())
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->keyBy('id');

        $currentSessionId = $request->session()->getId();

        return $rows->groupBy('user_id')->map(function ($sessions, $userId) use ($users, $currentSessionId) {
            $user = $users->get($userId);

            return [
                'user_id' => (int) $userId,
                'name' => $user?->full_name ?? 'Unknown user',
                'role' => $user?->getRoleNames()->first() ?? '—',
                'sessions' => $sessions->map(function ($session) use ($currentSessionId) {
                    return [
                        'id' => $session->id,
                        'ip_address' => $session->ip_address,
                        'device' => self::describeUserAgent($session->user_agent),
                        'last_active' => date('Y-m-d\TH:i:s\Z', $session->last_activity),
                        'is_current' => $session->id === $currentSessionId,
                    ];
                })->values(),
            ];
        })->values()->all();
    }

    /**
     * Force-logout a single session. An admin can never force-logout
     * their own current session through this action — they already
     * have a normal Logout button for that, and this keeps the
     * action unambiguous (it only ever ends *someone else's*
     * session).
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Administrator'), 403);

        if ($session === $request->session()->getId()) {
            return back()->withErrors(['session' => "You can't force-logout your own current session this way. Use Logout instead."]);
        }

        $deleted = DB::table('sessions')->where('id', $session)->delete();

        if (! $deleted) {
            return back()->withErrors(['session' => 'That session was already ended.']);
        }

        return back()->with('success', 'User has been logged out.');
    }

    /**
     * Minimal, dependency-free user-agent summary — good enough for
     * "Chrome on Windows" / "Safari on iPhone" without pulling in a
     * full UA-parsing package for a single admin screen.
     */
    private static function describeUserAgent(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') && str_contains($userAgent, 'Mobile') === false && (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) === false => 'macOS',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? "{$browser} on {$platform}" : $browser;
    }
}