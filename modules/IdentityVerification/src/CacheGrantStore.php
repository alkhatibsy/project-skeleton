<?php

declare(strict_types=1);

namespace Modules\IdentityVerification;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Stores grants in a Laravel cache store.
 *
 * Single use relies on `Repository::add()` being atomic, which holds for the
 * redis, memcached, database, dynamodb and file stores. The array store is
 * only atomic within one process, so use it for tests only.
 */
final class CacheGrantStore implements GrantStore
{
    private const PREFIX = 'identity-verification:';

    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function put(Grant $grant): void
    {
        $this->cache->put(
            $this->grantKey($grant->guard, $grant->userId, $grant->purpose, $grant->sessionFingerprint),
            $grant->toArray(),
            $this->secondsUntil($grant->expiresAt),
        );
    }

    public function find(string $guard, string $userId, string $purpose, string $sessionFingerprint): ?Grant
    {
        $data = $this->cache->get($this->grantKey($guard, $userId, $purpose, $sessionFingerprint));

        if (! is_array($data)) {
            return null;
        }

        $grant = Grant::fromArray($data);

        if ($this->cache->has($this->consumedKey($grant->id))) {
            return null;
        }

        return $grant;
    }

    public function consume(Grant $grant): bool
    {
        // `add()` only writes when the key is missing, so it doubles as an
        // atomic test-and-set. The marker outlives the grant itself.
        $won = $this->cache->add(
            $this->consumedKey($grant->id),
            true,
            $this->secondsUntil($grant->expiresAt) + 60,
        );

        if ($won) {
            $this->cache->forget(
                $this->grantKey($grant->guard, $grant->userId, $grant->purpose, $grant->sessionFingerprint),
            );
        }

        return $won;
    }

    public function generation(string $guard, string $userId): int
    {
        return (int) $this->cache->get($this->generationKey($guard, $userId), 0);
    }

    public function revokeAll(string $guard, string $userId): void
    {
        $key = $this->generationKey($guard, $userId);

        $this->cache->add($key, 0);
        $this->cache->increment($key);
    }

    private function grantKey(string $guard, string $userId, string $purpose, string $sessionFingerprint): string
    {
        return self::PREFIX.'grant:'.hash('sha256', implode("\0", [$guard, $userId, $purpose, $sessionFingerprint]));
    }

    private function consumedKey(string $grantId): string
    {
        return self::PREFIX.'consumed:'.$grantId;
    }

    private function generationKey(string $guard, string $userId): string
    {
        return self::PREFIX.'generation:'.hash('sha256', $guard."\0".$userId);
    }

    private function secondsUntil(CarbonImmutable $moment): int
    {
        return max(1, (int) ceil(CarbonImmutable::now()->diffInSeconds($moment, false)));
    }
}
