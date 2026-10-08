<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        collect(config('fleet_rbac.permissions', []))
            ->flatten()
            ->unique()
            ->each(function (string $permission): void {
                Gate::define(
                    $permission,
                    fn ($user): bool => $user->hasPermission($permission)
                );
            });

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
