<?php

declare(strict_types=1);

use App\Filament\Pages\AccountSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\IdentityVerification\IdentityVerifier;
use Modules\IdentityVerification\Tests\Fixtures\ProtectedActionsPage;
use Modules\IdentityVerification\Tests\Fixtures\ProtectedTestAction;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    ProtectedTestAction::$executions = [];
    ProtectedActionsPage::$plainExecutions = [];

    $this->user = User::factory()->create([
        'email' => 'old@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $this->actingAs($this->user);
});

/**
 * What a crafted Livewire request can do: write the public `mountedActions`
 * property straight to the protected action, then call it.
 */
function forceMountedAction(object $component, string $name, array $data = [], array $arguments = []): object
{
    return $component
        ->set('mountedActions', [[
            'name' => $name,
            'arguments' => $arguments,
            'context' => [],
            'data' => $data,
        ]])
        ->call('callMountedAction');
}

it('rejects a direct call to the protected action without a grant', function (): void {
    $component = Livewire::test(AccountSettings::class);

    forceMountedAction($component, 'updateEmail', ['email' => 'attacker@example.com'])
        ->assertNotified(__('identity-verification::messages.expired'));

    expect($this->user->refresh()->email)->toBe('old@example.com');
});

it('rejects a replayed grant after it was used once', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'correct-horse-battery'])
        ->callMountedAction()
        ->fillForm(['email' => 'first@example.com'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    forceMountedAction($component, 'updateEmail', ['email' => 'second@example.com']);

    expect($this->user->refresh()->email)->toBe('first@example.com');
});

it('rejects a grant that expired while the form was open', function (): void {
    config()->set('identity-verification.grant_ttl', 60);

    $component = Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'correct-horse-battery'])
        ->callMountedAction()
        ->assertActionMounted('updateEmail');

    $this->travel(61)->seconds();

    $component
        ->fillForm(['email' => 'late@example.com'])
        ->callMountedAction()
        ->assertNotified(__('identity-verification::messages.expired'))
        ->assertActionNotMounted('updateEmail');

    expect($this->user->refresh()->email)->toBe('old@example.com');
});

it('does not let a grant for one purpose run an action with another purpose', function (): void {
    $component = Livewire::test(ProtectedActionsPage::class)
        ->mountAction('alpha')
        ->assertActionMounted('identityVerification')
        ->fillForm(['password' => 'correct-horse-battery'])
        ->callMountedAction()
        ->assertActionMounted('alpha');

    forceMountedAction($component, 'beta');

    expect(ProtectedTestAction::$executions)->toBe([])
        ->and(app(IdentityVerifier::class)->hasValidGrant('purpose_alpha', 'web'))->toBeTrue();

    $component->mountAction('alpha')->callMountedAction();

    expect(ProtectedTestAction::$executions)->toBe(['alpha' => 1]);
});

it('takes the purpose from the protected action, never from verification arguments', function (): void {
    Livewire::test(ProtectedActionsPage::class)
        ->mountAction('identityVerification', [
            'action' => 'alpha',
            'purpose' => 'purpose_beta',
            'method' => 'carrier_pigeon',
        ])
        ->fillForm(['password' => 'correct-horse-battery'])
        ->callMountedAction()
        ->assertActionMounted('alpha');

    $verifier = app(IdentityVerifier::class);

    expect($verifier->hasValidGrant('purpose_alpha', 'web'))->toBeTrue()
        ->and($verifier->hasValidGrant('purpose_beta', 'web'))->toBeFalse();
});

it('issues no grant when verification arguments point at an unprotected or unknown action', function (string $target): void {
    Livewire::test(ProtectedActionsPage::class)
        ->mountAction('identityVerification', ['action' => $target])
        ->assertActionNotMounted('identityVerification')
        ->assertNotified(__('identity-verification::messages.required'));

    // Even forcing the modal state and submitting a correct password does nothing.
    $component = Livewire::test(ProtectedActionsPage::class);
    forceMountedAction($component, 'identityVerification', ['password' => 'correct-horse-battery'], ['action' => $target]);

    expect(ProtectedTestAction::$executions)->toBe([])
        ->and(ProtectedActionsPage::$plainExecutions)->toBe([]);
})->with(['plain', 'doesNotExist', 'identityVerification']);

it('does not let a verified but unauthorized user run the action', function (): void {
    app(IdentityVerifier::class)->verify('update_email', ['password' => 'correct-horse-battery'], guard: 'web');

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'updateEmail' ? false : null);

    $component = Livewire::test(AccountSettings::class);
    forceMountedAction($component, 'updateEmail', ['email' => 'attacker@example.com']);

    expect($this->user->refresh()->email)->toBe('old@example.com');
});

it('does not let another user spend a grant through the UI', function (): void {
    app(IdentityVerifier::class)->verify('update_email', ['password' => 'correct-horse-battery'], guard: 'web');

    $other = User::factory()->create(['email' => 'other@example.com']);
    $this->actingAs($other);

    Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->assertActionMounted('identityVerification')
        ->assertActionNotMounted('updateEmail');

    forceMountedAction(Livewire::test(AccountSettings::class), 'updateEmail', ['email' => 'attacker@example.com']);

    expect($other->refresh()->email)->toBe('other@example.com');
});

it('fails closed for every protected action when no grant exists', function (): void {
    $component = Livewire::test(ProtectedActionsPage::class);

    forceMountedAction($component, 'alpha');
    forceMountedAction($component, 'beta');

    expect(ProtectedTestAction::$executions)->toBe([]);
});
