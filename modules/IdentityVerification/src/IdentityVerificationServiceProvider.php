<?php

declare(strict_types=1);

namespace Modules\IdentityVerification;

use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\IdentityVerification\Http\Middleware\RequireIdentityVerification;

final class IdentityVerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/identity-verification.php', 'identity-verification');

        $this->app->singleton(GrantStore::class, fn (Application $app): GrantStore => new CacheGrantStore(
            $app->make('cache')->store($app->make('config')->get('identity-verification.cache_store')),
        ));

        $this->app->singleton(IdentityVerifier::class);
    }

    public function boot(Router $router): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'identity-verification');

        $this->publishes([
            __DIR__.'/../config/identity-verification.php' => config_path('identity-verification.php'),
        ], 'identity-verification-config');

        $router->aliasMiddleware('identity.verified', RequireIdentityVerification::class);

        $this->revokeGrantsWhenCredentialsChange();
    }

    /**
     * A grant proves the user knew their credentials a moment ago. Once those
     * credentials change, or other sessions are signed out, that proof is stale.
     */
    private function revokeGrantsWhenCredentialsChange(): void
    {
        $revoke = function (mixed $user): void {
            if ($user instanceof Authenticatable) {
                $this->app->make(IdentityVerifier::class)->revokeAll($user);
            }
        };

        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $revoke($event->user));
        Event::listen(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $revoke($event->user));

        foreach ($this->authenticatableModels() as $model) {
            Event::listen("eloquent.updated: {$model}", function (Model $user) use ($revoke): void {
                $passwordColumn = method_exists($user, 'getAuthPasswordName')
                    ? $user->getAuthPasswordName()
                    : 'password';

                if ($user->wasChanged($passwordColumn)) {
                    $revoke($user);
                }
            });
        }
    }

    /**
     * @return array<class-string<Model>>
     */
    private function authenticatableModels(): array
    {
        return collect((array) config('auth.providers', []))
            ->where('driver', 'eloquent')
            ->pluck('model')
            ->filter(fn (mixed $model): bool => is_string($model) && is_subclass_of($model, Model::class))
            ->unique()
            ->values()
            ->all();
    }
}
