<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        RateLimiter::for('route-traffic', function (Request $request): array {
            abort_unless($request->user(), 401);

            $userId = (string) $request->user()->getAuthIdentifier();
            $limits = [$this->routeTrafficLimit($userId, 'all', 20)];

            if ($request->boolean('include_comparison')) {
                $limits[] = $this->routeTrafficLimit($userId, 'comparison', 6);
            }

            return $limits;
        });

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

    private function routeTrafficLimit(string $userId, string $scope, int $maxAttempts): Limit
    {
        return Limit::perMinute($maxAttempts)
            ->by($scope.':'.$userId)
            ->response(static function (Request $request, array $headers) use ($scope): JsonResponse {
                return response()->json([
                    'success' => false,
                    'code' => 'ROUTE_RATE_LIMITED',
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => (int) $headers['Retry-After'],
                    'scope' => $scope,
                ], 429, $headers);
            });
    }
}
