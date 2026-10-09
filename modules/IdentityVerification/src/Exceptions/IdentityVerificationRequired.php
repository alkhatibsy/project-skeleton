<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Thrown when a protected operation runs without a valid grant. As an HTTP
 * exception it renders as 403 and is not written to the error log.
 */
final class IdentityVerificationRequired extends AccessDeniedHttpException
{
    public static function make(): self
    {
        return new self(__('identity-verification::messages.required'));
    }
}
