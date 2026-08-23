<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ACTIVITY LOG — Administrator-only "who did what, when" tab on the
 * Settings page, same pattern as ActiveSessionController: a plain
 * static builder method called from SettingsController::index()
 * (wrapped in Inertia::lazy() so it's only queried when the tab is
 * actually opened or its filters change — see the
 * `router.reload({ only: ['activityLog'] })` calls on the frontend).
 *
 * Read-only — there is no write action here at all; every row is
 * created elsewhere via ActivityLogService::record().
 */
class ActivityLogController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Build the paginated, filtered Activity Log payload consumed by
     * the Settings page's Activity Log tab.
     *
     * @return array{
     *     data: list<array{id:int,actor:?string,role:?string,action:string,description:string,created_at:string}>,
     *     current_page:int, last_page:int, total:int,
     *     filters: array{action:?string,user_id:?int,date_from:?string,date_to:?string},
     *     action_options: list<string>,
     *     user_options: list<array{id:int,name:string}>,
     * }
     */
    public static function activityLog(Request $request): array
    {
        $query = ActivityLog::query()->with('user')->latest('created_at');

        $action = $request->string('log_action')->toString() ?: null;
        $userId = $request->integer('log_user_id') ?: null;
        $dateFrom = $request->string('log_date_from')->toString() ?: null;
        $dateTo = $request->string('log_date_to')->toString() ?: null;

        if ($action) {
            $query->where('action', $action);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        /** @var LengthAwarePaginator $paginated */
        $paginated = $query->paginate(
            self::PER_PAGE,
            ['*'],
            'log_page',
            (int) $request->input('log_page', 1),
        );

        return [
            'data' => collect($paginated->items())->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'actor' => $log->user?->full_name ?? 'System',
                'role' => $log->user?->getRoleNames()->first(),
                'action' => $log->action,
                'description' => $log->description,
                'created_at' => $log->created_at->toIso8601String(),
            ])->all(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'filters' => [
                'action' => $action,
                'user_id' => $userId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'action_options' => ActivityLogService::actions(),
            'user_options' => User::query()
                ->whereIn('id', ActivityLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->full_name])
                ->values()
                ->all(),
        ];
    }
}