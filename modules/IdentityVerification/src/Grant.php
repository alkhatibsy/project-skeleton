<?php

declare(strict_types=1);

namespace Modules\IdentityVerification;

use Carbon\CarbonImmutable;

/**
 * Server-side proof that a user recently verified their identity for one
 * purpose. Grants never leave the server; the browser only ever learns
 * whether the next step is allowed.
 */
final readonly class Grant
{
    public function __construct(
        public string $id,
        public string $guard,
        public string $userId,
        public string $purpose,
        public string $method,
        public string $sessionFingerprint,
        public int $generation,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->expiresAt);
    }

    /**
     * Every scope attribute is compared, not just the storage key, so a grant
     * can never authorize a different user, guard, session or purpose.
     */
    public function matches(string $guard, string $userId, string $purpose, string $sessionFingerprint): bool
    {
        return hash_equals($this->guard, $guard)
            && hash_equals($this->userId, $userId)
            && hash_equals($this->purpose, $purpose)
            && hash_equals($this->sessionFingerprint, $sessionFingerprint);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'guard' => $this->guard,
            'user_id' => $this->userId,
            'purpose' => $this->purpose,
            'method' => $this->method,
            'session' => $this->sessionFingerprint,
            'generation' => $this->generation,
            'issued_at' => $this->issuedAt->getTimestamp(),
            'expires_at' => $this->expiresAt->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            guard: (string) $data['guard'],
            userId: (string) $data['user_id'],
            purpose: (string) $data['purpose'],
            method: (string) $data['method'],
            sessionFingerprint: (string) $data['session'],
            generation: (int) $data['generation'],
            issuedAt: CarbonImmutable::createFromTimestamp((int) $data['issued_at']),
            expiresAt: CarbonImmutable::createFromTimestamp((int) $data['expires_at']),
        );
    }
}
