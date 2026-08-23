<?php

namespace App\Providers;

use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Policies\FacultyLoadRequestPolicy;
use App\Policies\FacultyPolicy;
use App\Policies\RoomPolicy;
use App\Policies\SectionPolicy;
use App\Policies\SubjectPolicy;
use App\Policies\UserPolicy;
use App\Support\AccessScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Faculty::class => FacultyPolicy::class,
        FacultyLoadRequest::class => FacultyLoadRequestPolicy::class,
        Subject::class => SubjectPolicy::class,
        Room::class => RoomPolicy::class,
        Section::class => SectionPolicy::class,
        User::class => UserPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Administrator has FULL SYSTEM ACCESS (spec Section 2) —
        // short-circuit every gate/policy check. Every other role must
        // pass its policy explicitly; there is no other blanket bypass.
        Gate::before(function (User $user, string $ability) {
            return AccessScope::isAdministrator($user) ? true : null;
        });

        // Coarse, module-level gates for pages/actions that aren't
        // tied to a single Eloquent model (Reports, Settings, Academic
        // Structure, Scheduling engine actions, Curriculum). The
        // per-model Policies above remain authoritative for per-record
        // actions and query scoping.
        Gate::define('manage-users', fn (User $user) => AccessScope::isAdministrator($user));

        Gate::define('view-reports', fn (User $user) => $user->hasAnyRole([
            'Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC',
        ]));

        Gate::define('manage-settings', fn (User $user) => $user->hasAnyRole(['Administrator', 'Registrar']));

        Gate::define('manage-academic-structure', fn (User $user) => $user->hasAnyRole(['Administrator', 'Registrar']));

        Gate::define('manage-academic-calendar', fn (User $user) => $user->hasAnyRole(['Administrator', 'Registrar']));

        Gate::define('manage-curriculum', fn (User $user) => $user->hasAnyRole(['Administrator', 'Registrar']));

        Gate::define('run-auto-schedule', fn (User $user) => $user->hasAnyRole([
            'Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC',
        ]));

        Gate::define('view-scheduling', fn (User $user) => $user->hasAnyRole([
            'Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC',
        ]));
    }
}