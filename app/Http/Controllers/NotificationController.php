<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCHEDULING NOTIFICATION SYSTEM — polling API (spec Section 11).
 *
 * Every endpoint here only ever READS or marks-read; nothing in this
 * controller creates a Notification — those are only ever written by
 * NotificationService from inside the scheduling operations that
 * trigger them (see SectionController::finalize()/unlock() and
 * SectionSubjectController::performScheduleAssignmentUpdate()).
 */
class NotificationController extends Controller
{
    /**
     * Full notification list/page (spec Section 21) with All/Unread
     * filtering via ?filter=unread.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('recipient_user_id', $user->id)
            ->when($request->query('filter') === 'unread', fn ($query) => $query->where('is_read', false))
            ->with(['actor:id,name,first_name,last_name,middle_name,suffix', 'section:id,section_code'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filter' => $request->query('filter', 'all'),
        ]);
    }

    /**
     * Lightweight payload for the header bell dropdown — capped list
     * + unread count, meant to be polled every 10–30s (spec Section
     * 11). Deliberately NOT paginated the same way as index() so the
     * poll stays cheap.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('recipient_user_id', $user->id)
            ->with(['actor:id,name,first_name,last_name,middle_name,suffix', 'section:id,section_code'])
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => Notification::query()
                ->where('recipient_user_id', $user->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => Notification::query()
                ->where('recipient_user_id', $request->user()->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /**
     * Mark one notification read (spec Section 13) and hand back the
     * route to the relevant Section's schedule so the frontend can
     * navigate there — see the `redirect` field. Scoped to the
     * requesting user's own notifications only; a recipient mismatch
     * is treated as "not found" rather than 403, so it doesn't leak
     * whether a given notification id exists for someone else.
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        $notification->markRead();

        return response()->json([
            'notification' => $notification,
            'redirect' => $this->routeFor($notification),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Convenience GET entry point (e.g. from an email or a bookmark)
     * that marks the notification read and redirects straight to its
     * Section's schedule (spec Section 14) — generated from the
     * Section id via the app's own routing, never a stored URL.
     */
    public function redirect(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        $notification->markRead();

        $route = $this->routeFor($notification);

        return $route ? redirect($route) : redirect()->route('scheduling.sections');
    }

    private function routeFor(Notification $notification): ?string
    {
        if (! $notification->section_id) {
            return null;
        }

        return route('scheduling.section-subjects.show', $notification->section_id);
    }
}