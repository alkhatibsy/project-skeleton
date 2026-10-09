<?php

declare(strict_types=1);

use App\Filament\Pages\AccountSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\IdentityVerification\IdentityVerifier;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->user = User::factory()->create([
        'email' => 'old@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $this->actingAs($this->user);
});

it('shows the verification modal instead of the protected form', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->assertActionMounted('identityVerification')
        ->assertActionNotMounted('updateEmail')
        ->assertMountedActionModalSee('Confirm your identity');

    // The protected action's form was never filled, so its data is not in state.
    expect($component->instance()->mountedActions)->toHaveCount(1)
        ->and(json_encode($component->instance()->mountedActions))->not->toContain('old@example.com');
});

it('keeps the verification modal open with an error when the password is wrong', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'wrong-password'])
        ->callMountedAction()
        ->assertHasActionErrors(['password'])
        ->assertActionMounted('identityVerification')
        ->assertActionNotMounted('updateEmail');

    expect($component->instance()->mountedActions[0]['data']['password'] ?? null)->toBeNull()
        ->and(app(IdentityVerifier::class)->hasValidGrant('update_email', 'web'))->toBeFalse();
});

it('replaces the verification modal with the protected action after a correct password', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'correct-horse-battery'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionMounted('updateEmail')
        ->assertActionNotMounted('identityVerification')
        ->assertSchemaStateSet(['email' => 'old@example.com']);

    expect(json_encode($component->instance()->mountedActions))->not->toContain('correct-horse-battery');

    $component
        ->fillForm(['email' => 'new@example.com'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Email address updated');

    expect($this->user->refresh()->email)->toBe('new@example.com')
        ->and(app(IdentityVerifier::class)->hasValidGrant('update_email', 'web'))->toBeFalse();
});

it('does not run the protected action when verification is cancelled', function (): void {
    Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->assertActionMounted('identityVerification')
        ->unmountAction()
        ->assertActionNotMounted('identityVerification')
        ->assertActionNotMounted('updateEmail');

    expect($this->user->refresh()->email)->toBe('old@example.com')
        ->and(app(IdentityVerifier::class)->hasValidGrant('update_email', 'web'))->toBeFalse();
});

it('keeps the protected action\'s own validation, without spending the grant', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'correct-horse-battery'])
        ->callMountedAction()
        ->fillForm(['email' => 'not-an-email'])
        ->callMountedAction()
        ->assertHasActionErrors(['email' => 'email'])
        ->fillForm(['email' => 'taken@example.com'])
        ->callMountedAction()
        ->assertHasActionErrors(['email' => 'unique'])
        ->assertActionMounted('updateEmail');

    expect($this->user->refresh()->email)->toBe('old@example.com')
        ->and(app(IdentityVerifier::class)->hasValidGrant('update_email', 'web'))->toBeTrue();
});

it('opens the protected form directly while a recent grant is still valid', function (): void {
    app(IdentityVerifier::class)->verify('update_email', ['password' => 'correct-horse-battery'], guard: 'web');

    Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->assertActionMounted('updateEmail')
        ->assertActionNotMounted('identityVerification');
});

it('keeps the protected action\'s authorization: unauthorized users never reach verification', function (): void {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'updateEmail' ? false : null);

    Livewire::test(AccountSettings::class)
        ->assertActionHidden('updateEmail')
        ->mountAction('updateEmail')
        ->assertActionNotMounted('identityVerification')
        ->assertActionNotMounted('updateEmail');
});

it('renders the account settings page', function (): void {
    Livewire::test(AccountSettings::class)
        ->assertOk()
        ->assertActionVisible('updateEmail');
});
