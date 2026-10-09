<?php

declare(strict_types=1);

namespace Modules\IdentityVerification;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\IdentityVerification\Exceptions\IdentityVerificationRequired;
use SensitiveParameter;

/**
 * Verifies the current user's identity and manages the grants that prove it.
 *
 * The user is always taken from the guard, never from the caller, so a
 * request can only ever verify (and spend grants) as itself.
 */
final class IdentityVerifier
{
    private const PURPOSE_PATTERN = '/\A[A-Za-z0-9_.:\-]{1,120}\z/';

    /**
     * @var array<string, class-string<VerificationMethod>|Closure(Container): VerificationMethod>
     */
    private array $extensions = [];

    public function __construct(
        private readonly Container $container,
        private readonly AuthFactory $auth,
        private readonly GrantStore $grants,
        private readonly RateLimiter $limiter,
        private readonly ValidationFactory $validator,
        private readonly Config $config,
    ) {}

    /**
     * Register a verification method at runtime, for example from a package.
     *
     * @param  class-string<VerificationMethod>|Closure(Container): VerificationMethod  $method
     */
    public function extend(string $name, string|Closure $method): void
    {
        $this->extensions[$name] = $method;
    }

    public function method(string $name): VerificationMethod
    {
        $definition = $this->extensions[$name]
            ?? $this->config->get("identity-verification.methods.{$name}")
            ?? throw new InvalidArgumentException("Identity verification method [{$name}] is not registered.");

        $method = $definition instanceof Closure
            ? $definition($this->container)
            : $this->container->make($definition);

        if (! $method instanceof VerificationMethod) {
            throw new InvalidArgumentException("Identity verification method [{$name}] must implement ".VerificationMethod::class.'.');
        }

        return $method;
    }

    public function defaultMethod(): string
    {
        return (string) $this->config->get('identity-verification.default_method', 'password');
    }

    public function defaultGuard(): string
    {
        return (string) ($this->config->get('identity-verification.guard') ?? $this->config->get('auth.defaults.guard'));
    }

    /**
     * Check the credentials and, if they are right, issue a grant for the purpose.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthenticationException when no user is signed in on the guard.
     * @throws ValidationException when the input is invalid, wrong or throttled.
     */
    public function verify(
        string $purpose,
        #[SensitiveParameter] array $input,
        ?string $method = null,
        ?string $guard = null,
    ): Grant {
        $this->assertValidPurpose($purpose);

        $guard ??= $this->defaultGuard();
        $methodName = $method ?? $this->defaultMethod();
        $user = $this->user($guard) ?? throw new AuthenticationException(guards: [$guard]);
        $verificationMethod = $this->method($methodName);

        $rules = $verificationMethod->rules();
        $errorKey = (string) (array_key_first($rules) ?? 'credentials');
        $throttleKey = $this->throttleKey($guard, $user);

        if ($this->limiter->tooManyAttempts($throttleKey, $this->maxAttempts())) {
            throw ValidationException::withMessages([
                $errorKey => __('identity-verification::messages.throttled', [
                    'seconds' => $this->limiter->availableIn($throttleKey),
                ]),
            ]);
        }

        $validated = $this->validator->make($input, $rules)->validate();

        if (! $verificationMethod->verify($user, $validated, $guard)) {
            $this->limiter->hit($throttleKey, $this->decaySeconds());

            throw ValidationException::withMessages([
                $errorKey => __('identity-verification::messages.failed'),
            ]);
        }

        $this->limiter->clear($throttleKey);

        $userId = $this->userId($user);
        $now = CarbonImmutable::now();

        $grant = new Grant(
            id: bin2hex(random_bytes(20)),
            guard: $guard,
            userId: $userId,
            purpose: $purpose,
            method: $methodName,
            sessionFingerprint: $this->sessionFingerprint(),
            generation: $this->grants->generation($guard, $userId),
            issuedAt: $now,
            expiresAt: $now->addSeconds($this->grantTtl()),
        );

        $this->grants->put($grant);

        return $grant;
    }

    /**
     * Whether the current user holds a usable grant. Does not consume it.
     */
    public function hasValidGrant(string $purpose, ?string $guard = null): bool
    {
        return $this->findValidGrant($purpose, $guard ?? $this->defaultGuard()) !== null;
    }

    /**
     * Spend the current user's grant for the purpose. Call this immediately
     * before the sensitive operation runs.
     *
     * @throws IdentityVerificationRequired when there is no usable grant.
     */
    public function consume(string $purpose, ?string $guard = null): Grant
    {
        $grant = $this->findValidGrant($purpose, $guard ?? $this->defaultGuard());

        if (! $grant || ! $this->grants->consume($grant)) {
            throw IdentityVerificationRequired::make();
        }

        return $grant;
    }

    /**
     * Revoke every grant the user holds across all sessions, on one guard or,
     * when none is given, on every configured guard.
     */
    public function revokeAll(Authenticatable $user, ?string $guard = null): void
    {
        $guards = $guard !== null
            ? [$guard]
            : array_keys((array) $this->config->get('auth.guards', []));

        foreach ($guards as $guardName) {
            $this->grants->revokeAll((string) $guardName, $this->userId($user));
        }
    }

    private function findValidGrant(string $purpose, string $guard): ?Grant
    {
        $this->assertValidPurpose($purpose);

        $user = $this->user($guard);

        if (! $user) {
            return null;
        }

        $userId = $this->userId($user);
        $session = $this->sessionFingerprint();
        $grant = $this->grants->find($guard, $userId, $purpose, $session);

        if (
            ! $grant
            || ! $grant->matches($guard, $userId, $purpose, $session)
            || $grant->isExpired()
            || $grant->generation !== $this->grants->generation($guard, $userId)
        ) {
            return null;
        }

        return $grant;
    }

    private function user(string $guard): ?Authenticatable
    {
        return $this->auth->guard($guard)->user();
    }

    private function userId(Authenticatable $user): string
    {
        return (string) $user->getAuthIdentifier();
    }

    /**
     * Grants are bound to the session, so they vanish when it is invalidated
     * or regenerated (for example on login or logout).
     */
    private function sessionFingerprint(): string
    {
        return hash('sha256', (string) $this->container->make('session.store')->getId());
    }

    private function throttleKey(string $guard, Authenticatable $user): string
    {
        return 'identity-verification:'.hash('sha256', $guard."\0".$this->userId($user));
    }

    private function assertValidPurpose(string $purpose): void
    {
        if (! preg_match(self::PURPOSE_PATTERN, $purpose)) {
            throw new InvalidArgumentException('Identity verification purposes may only contain letters, numbers, dots, colons, dashes and underscores.');
        }
    }

    private function grantTtl(): int
    {
        return max(1, (int) $this->config->get('identity-verification.grant_ttl', 300));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('identity-verification.rate_limit.max_attempts', 5));
    }

    private function decaySeconds(): int
    {
        return max(1, (int) $this->config->get('identity-verification.rate_limit.decay_seconds', 60));
    }
}
