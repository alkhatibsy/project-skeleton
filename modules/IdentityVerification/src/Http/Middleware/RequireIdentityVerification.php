<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\IdentityVerification\IdentityVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects a route outside Filament: `->middleware('identity.verified:delete_account')`.
 *
 * The grant is spent before the controller runs, so a request that later
 * fails validation still needs a fresh verification. Put it after `auth`.
 */
final class RequireIdentityVerification
{
    public function __construct(
        private readonly IdentityVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next, string $purpose, ?string $guard = null): Response
    {
        $this->verifier->consume($purpose, $guard);

        return $next($request);
    }
}
