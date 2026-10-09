<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Filament\Concerns;

use Modules\IdentityVerification\Filament\IdentityVerificationAction;

/**
 * Add to any Filament page or Livewire component that hosts actions using
 * RequiresIdentityVerification. It registers the verification modal at the
 * root of the component so a protected action can be replaced by it from
 * any context: header, page, table row or schema component.
 */
trait InteractsWithIdentityVerification
{
    public function identityVerificationAction(): IdentityVerificationAction
    {
        return IdentityVerificationAction::make();
    }
}
