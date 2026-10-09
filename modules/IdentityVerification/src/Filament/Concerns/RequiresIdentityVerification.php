<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use LogicException;
use Modules\IdentityVerification\Exceptions\IdentityVerificationRequired;
use Modules\IdentityVerification\Filament\IdentityVerificationAction;
use Modules\IdentityVerification\IdentityVerifier;
use Throwable;

/**
 * Use on a Filament action class whose execution is sensitive:
 *
 *     class UpdateEmailAction extends Action implements HasIdentityVerification
 *     {
 *         use RequiresIdentityVerification;
 *     }
 *
 *     UpdateEmailAction::make()->requiresIdentityVerification(purpose: 'update_email');
 *
 * The checks live in overridden lifecycle methods rather than in callbacks,
 * so later calls like `->action()` or `->fillForm()` cannot remove them:
 *
 * - `mount()` swaps the action for the verification modal until a grant exists,
 *   so the action's form is never filled or shown before verification.
 * - `callBefore()` and `call()` spend the grant on the server right before the
 *   action's own logic, however the request reached them.
 *
 * @mixin Action
 */
trait RequiresIdentityVerification
{
    protected ?string $identityVerificationPurpose = null;

    protected ?string $identityVerificationMethod = null;

    protected bool $hasSpentIdentityVerificationGrant = false;

    public function requiresIdentityVerification(string $purpose, ?string $method = null): static
    {
        $this->identityVerificationPurpose = $purpose;
        $this->identityVerificationMethod = $method;

        return $this;
    }

    public function isIdentityVerificationRequired(): bool
    {
        return $this->identityVerificationPurpose !== null;
    }

    public function getIdentityVerificationPurpose(): ?string
    {
        return $this->identityVerificationPurpose;
    }

    public function getIdentityVerificationMethod(): string
    {
        return $this->identityVerificationMethod ?? app(IdentityVerifier::class)->defaultMethod();
    }

    public function getIdentityVerificationGuard(): string
    {
        return Filament::getAuthGuard();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function mount(array $parameters): mixed
    {
        if ($this->isIdentityVerificationRequired() && ! $this->hasIdentityVerificationGrant()) {
            $this->replaceWithIdentityVerification();

            return null;
        }

        return parent::mount($parameters);
    }

    public function callBefore(): mixed
    {
        $this->spendIdentityVerificationGrant();

        try {
            return parent::callBefore();
        } catch (Throwable $exception) {
            // Only a completed `callBefore()` may hand the spent grant to `call()`.
            $this->hasSpentIdentityVerificationGrant = false;

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function call(array $parameters = []): mixed
    {
        if (! $this->hasSpentIdentityVerificationGrant) {
            $this->spendIdentityVerificationGrant();
        }

        try {
            return parent::call($parameters);
        } finally {
            $this->hasSpentIdentityVerificationGrant = false;
        }
    }

    protected function hasIdentityVerificationGrant(): bool
    {
        return app(IdentityVerifier::class)->hasValidGrant(
            (string) $this->getIdentityVerificationPurpose(),
            $this->getIdentityVerificationGuard(),
        );
    }

    protected function spendIdentityVerificationGrant(): void
    {
        if (! $this->isIdentityVerificationRequired()) {
            return;
        }

        try {
            app(IdentityVerifier::class)->consume(
                (string) $this->getIdentityVerificationPurpose(),
                $this->getIdentityVerificationGuard(),
            );
        } catch (IdentityVerificationRequired) {
            $this->hasSpentIdentityVerificationGrant = false;

            Notification::make()
                ->danger()
                ->title(__('identity-verification::messages.expired'))
                ->send();

            $this->cancel();
        }

        $this->hasSpentIdentityVerificationGrant = true;
    }

    protected function replaceWithIdentityVerification(): void
    {
        $livewire = $this->getLivewire();
        $nestingIndex = $this->getNestingIndex();

        if ($nestingIndex !== 0) {
            // `replaceMountedAction()` clears the whole stack, which would close
            // the parent modals of a nested action.
            throw new LogicException('Actions that require identity verification must be mounted at the root, not nested inside another action\'s modal.');
        }

        if (! method_exists($livewire, 'replaceMountedAction') || ! method_exists($livewire, 'identityVerificationAction')) {
            throw new LogicException('Add the '.InteractsWithIdentityVerification::class.' trait to ['.$livewire::class.'] to use actions that require identity verification.');
        }

        $mounted = $livewire->mountedActions[$nestingIndex] ?? [];

        $livewire->replaceMountedAction(IdentityVerificationAction::NAME, [
            'action' => $this->getName(),
            'arguments' => $mounted['arguments'] ?? [],
            'context' => $mounted['context'] ?? [],
        ]);
    }
}
