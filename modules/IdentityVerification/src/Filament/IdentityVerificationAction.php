<?php

declare(strict_types=1);

namespace Modules\IdentityVerification\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Modules\IdentityVerification\Filament\Contracts\HasIdentityVerification;
use Modules\IdentityVerification\IdentityVerifier;

/**
 * The modal that collects credentials and, on success, swaps itself for the
 * protected action with `replaceMountedAction()`.
 *
 * Its arguments only name the protected action to return to. They come from
 * the browser, so nothing security-relevant is read from them: the purpose,
 * method and guard always come from the resolved protected action, and a
 * grant is only issued for an action the user can currently see and run.
 */
final class IdentityVerificationAction extends Action
{
    public const NAME = 'identityVerification';

    /**
     * @var array<string, Closure(): array<Component|Field>>
     */
    private static array $methodSchemas = [];

    private (Action&HasIdentityVerification)|null $resolvedTarget = null;

    private ?string $resolvedTargetKey = null;

    public static function getDefaultName(): string
    {
        return self::NAME;
    }

    /**
     * Fields shown for a verification method. Register one per extra method,
     * for example in a service provider's `boot()`.
     *
     * @param  Closure(): array<Component|Field>  $schema
     */
    public static function registerMethodSchema(string $method, Closure $schema): void
    {
        self::$methodSchemas[$method] = $schema;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('identity-verification::messages.modal.heading'))
            ->modalHeading(__('identity-verification::messages.modal.heading'))
            ->modalDescription(__('identity-verification::messages.modal.description'))
            ->modalSubmitActionLabel(__('identity-verification::messages.modal.submit'))
            ->modalWidth(Width::Medium)
            ->schema(static fn (IdentityVerificationAction $action): array => $action->getMethodSchema())
            ->mountUsing(static function (IdentityVerificationAction $action, ?Schema $schema): void {
                if (! $action->getTarget()) {
                    $action->cancelAsUnavailable();
                }

                $schema?->fill();
            })
            ->action(static function (IdentityVerificationAction $action, array $data, Schema $schema): void {
                $action->verifyAndContinue($data, $schema);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function verifyAndContinue(array $data, Schema $schema): void
    {
        $target = $this->getTarget() ?? $this->cancelAsUnavailable();

        try {
            app(IdentityVerifier::class)->verify(
                purpose: (string) $target->getIdentityVerificationPurpose(),
                input: $data,
                method: $target->getIdentityVerificationMethod(),
                guard: $target->getIdentityVerificationGuard(),
            );
        } catch (ValidationException $exception) {
            // Never send the rejected secret back to the browser.
            $schema->fill();

            $statePath = $schema->getStatePath();

            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => ["{$statePath}.{$key}" => $messages])
                    ->all(),
            );
        }

        $arguments = $this->getArguments();

        $this->getLivewire()->replaceMountedAction(
            $target->getName(),
            $this->arrayArgument($arguments, 'arguments'),
            $this->arrayArgument($arguments, 'context'),
        );
    }

    /**
     * @return array<Component|Field>
     */
    public function getMethodSchema(): array
    {
        $method = $this->getTarget()?->getIdentityVerificationMethod()
            ?? app(IdentityVerifier::class)->defaultMethod();

        if (isset(self::$methodSchemas[$method])) {
            return (self::$methodSchemas[$method])();
        }

        return match ($method) {
            'password' => [
                TextInput::make('password')
                    ->label(__('identity-verification::messages.fields.password'))
                    ->password()
                    ->required()
                    ->autocomplete('current-password')
                    ->autofocus(),
            ],
            default => throw new \LogicException("No form is registered for identity verification method [{$method}]. Call IdentityVerificationAction::registerMethodSchema()."),
        };
    }

    /**
     * The protected action this verification is for, or null if the arguments
     * do not point at one the current user may run.
     *
     * @return (Action&HasIdentityVerification)|null
     */
    public function getTarget(): ?Action
    {
        $arguments = $this->getArguments();
        $name = $arguments['action'] ?? null;
        $targetArguments = $this->arrayArgument($arguments, 'arguments');
        $context = $this->arrayArgument($arguments, 'context');

        if (! is_string($name) || $name === '' || $name === self::NAME) {
            return null;
        }

        $key = serialize([$name, $targetArguments, $context]);

        if ($this->resolvedTargetKey === $key) {
            return $this->resolvedTarget;
        }

        try {
            $target = $this->getLivewire()->getAction(
                [['name' => $name, 'arguments' => $targetArguments, 'context' => $context]],
                isMounting: false,
            );
        } catch (ActionNotResolvableException) {
            $target = null;
        }

        if (
            ! $target instanceof Action
            || ! $target instanceof HasIdentityVerification
            || ! $target->isIdentityVerificationRequired()
            || $target->isDisabled()
        ) {
            $target = null;
        }

        $this->resolvedTargetKey = $key;

        return $this->resolvedTarget = $target;
    }

    public function cancelAsUnavailable(): never
    {
        Notification::make()
            ->danger()
            ->title(__('identity-verification::messages.required'))
            ->send();

        $this->cancel();

        throw new \LogicException('Unreachable.');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function arrayArgument(array $arguments, string $key): array
    {
        return is_array($arguments[$key] ?? null) ? $arguments[$key] : [];
    }
}
