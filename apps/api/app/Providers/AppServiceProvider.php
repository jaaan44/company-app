<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
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
        // Shared by both the Admin (web) and mobile API login endpoints —
        // keyed by email+IP so one abusive client can't lock out another
        // legitimate user of the same account (CLAUDE.md §5/§19).
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Phase 5 — Roles & Permissions (DEC-028): the single, centralized
        // authorization entry point. Every ability check in the
        // application (Gate::allows/authorize, the 'can' middleware,
        // @can in Blade, $user->can() inside Policies) passes through
        // here first:
        //   - Administrator holds every ability, unconditionally — this
        //     is the one deliberate, documented override, not scattered
        //     `if ($user->hasRole('administrator'))` checks elsewhere.
        //   - Otherwise, if the ability name matches a permission the
        //     user's role grants, it's allowed.
        //   - Otherwise, return null (defer) rather than false (deny) —
        //     this lets genuine, unrelated Policy/Gate abilities (e.g. a
        //     future model Policy's 'update' check) resolve normally
        //     instead of being silently intercepted here. An ability
        //     that matches no permission and no other Gate/Policy still
        //     ends up denied by Laravel's own default-deny behavior.
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole(Role::ADMINISTRATOR)) {
                return true;
            }

            return $user->hasPermission($ability) ? true : null;
        });
    }
}
