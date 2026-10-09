<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Tests\Fixtures;

use Filament\Actions\Action;
use Modules\IdentityVerification\Filament\Concerns\RequiresIdentityVerification;
use Modules\IdentityVerification\Filament\Contracts\HasIdentityVerification;

final class ProtectedTestAction extends Action implements HasIdentityVerification
{
    use RequiresIdentityVerification;

    /**
     * @var array<string, int>
     */
    public static array $executions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresConfirmation()
            ->action(function (): void {
                self::$executions[$this->getName()] = (self::$executions[$this->getName()] ?? 0) + 1;
            });
    }
}
