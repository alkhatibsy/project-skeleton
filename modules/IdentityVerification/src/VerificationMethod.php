<?php

declare(strict_types=1);

namespace Modules\IdentityVerification;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A way for an already authenticated user to prove their identity again,
 * such as re-entering their password or confirming a one-time code.
 *
 * Implementations are resolved from the container, so they may use
 * constructor injection. Register them under a name in the
 * `identity-verification.methods` config option.
 */
interface VerificationMethod
{
    /**
     * Validation rules for the input this method expects, keyed by input name.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Check the submitted input for the given user. Must not throw on bad
     * credentials; return false instead so the caller can rate limit.
     *
     * @param  array<string, mixed>  $input  Input that already passed `rules()`.
     */
    public function verify(Authenticatable $user, array $input, string $guard): bool;
}
