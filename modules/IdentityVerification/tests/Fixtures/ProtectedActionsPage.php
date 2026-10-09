<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Modules\IdentityVerification\Filament\Concerns\InteractsWithIdentityVerification;

/**
 * Hosts two actions protected for different purposes and one unprotected
 * action, so tests can try to spend one grant on the wrong operation.
 */
final class ProtectedActionsPage extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithIdentityVerification;
    use InteractsWithSchemas;

    public static array $plainExecutions = [];

    public function alphaAction(): ProtectedTestAction
    {
        return ProtectedTestAction::make('alpha')->requiresIdentityVerification('purpose_alpha');
    }

    public function betaAction(): ProtectedTestAction
    {
        return ProtectedTestAction::make('beta')->requiresIdentityVerification('purpose_beta');
    }

    public function plainAction(): Action
    {
        return Action::make('plain')
            ->requiresConfirmation()
            ->action(fn () => self::$plainExecutions[] = true);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->alphaAction }}
                {{ $this->betaAction }}
                {{ $this->plainAction }}
                <x-filament-actions::modals />
            </div>
        BLADE;
    }
}
