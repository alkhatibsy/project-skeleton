<?php

declare(strict_types=1);

namespace Modules\IdentityVerification;

/**
 * Persists grants. Implementations must make `consume()` atomic: when two
 * requests race for the same grant, exactly one of them may win.
 */
interface GrantStore
{
    public function put(Grant $grant): void;

    /**
     * The unconsumed grant stored for this exact scope, if any. Expiry and
     * revocation are checked by the caller.
     */
    public function find(string $guard, string $userId, string $purpose, string $sessionFingerprint): ?Grant;

    /**
     * Mark the grant as used. Returns false when it was already consumed.
     */
    public function consume(Grant $grant): bool;

    /**
     * Grants issued under an older generation are revoked.
     */
    public function generation(string $guard, string $userId): int;

    /**
     * Revoke every grant the user holds for the guard, in every session.
     */
    public function revokeAll(string $guard, string $userId): void;
}
