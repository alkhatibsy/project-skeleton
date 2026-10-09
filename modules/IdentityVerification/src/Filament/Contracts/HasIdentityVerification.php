<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Filament\Contracts;

/**
 * Implemented by Filament actions that use the RequiresIdentityVerification
 * trait, so the verification modal can read their requirement in a typed way.
 */
interface HasIdentityVerification
{
    public function isIdentityVerificationRequired(): bool;

    public function getIdentityVerificationPurpose(): ?string;

    public function getIdentityVerificationMethod(): string;

    public function getIdentityVerificationGuard(): string;
}
