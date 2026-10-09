<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Methods;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\UserProvider;
use Modules\IdentityVerification\VerificationMethod;
use SensitiveParameter;

final class PasswordVerificationMethod implements VerificationMethod
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:4096'],
        ];
    }

    public function verify(
        Authenticatable $user,
        #[SensitiveParameter] array $input,
        string $guard,
    ): bool {
        $provider = $this->provider($guard);

        // The guard's user provider knows the password column and hasher, so
        // custom providers and user models keep working.
        return $provider->validateCredentials($user, ['password' => (string) $input['password']]);
    }

    private function provider(string $guard): UserProvider
    {
        $guardInstance = $this->auth->guard($guard);

        if (! method_exists($guardInstance, 'getProvider')) {
            throw new \LogicException("The [{$guard}] guard does not expose a user provider, so passwords cannot be verified.");
        }

        return $guardInstance->getProvider();
    }
}
