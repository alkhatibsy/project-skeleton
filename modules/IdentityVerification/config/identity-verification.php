<?php

declare(strict_types=1);

use Modules\IdentityVerification\Methods\PasswordVerificationMethod;

return [

    /*
     * Guard whose user is verified. Null uses `auth.defaults.guard`; the
     * Filament integration always passes the panel's guard.
     */
    'guard' => env('IDENTITY_VERIFICATION_GUARD'),

    'default_method' => 'password',

    /*
     * Verification methods by name. Each class must implement
     * Modules\IdentityVerification\VerificationMethod.
     */
    'methods' => [
        'password' => PasswordVerificationMethod::class,
    ],

    /*
     * How long a grant stays usable after a successful verification, in seconds.
     */
    'grant_ttl' => (int) env('IDENTITY_VERIFICATION_GRANT_TTL', 300),

    /*
     * Cache store holding grants. Null uses the default store. It must
     * support atomic `add()` (redis, memcached, database, dynamodb, file).
     */
    'cache_store' => env('IDENTITY_VERIFICATION_CACHE_STORE'),

    /*
     * Failed attempts allowed per user and guard before verification is
     * locked for `decay_seconds`.
     */
    'rate_limit' => [
        'max_attempts' => (int) env('IDENTITY_VERIFICATION_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('IDENTITY_VERIFICATION_DECAY_SECONDS', 60),
    ],

];
