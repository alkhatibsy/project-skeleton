<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\IdentityVerification\Grant;
use Modules\IdentityVerification\IdentityVerifier;

beforeEach(function (): void {
    $this->user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->verifier = app(IdentityVerifier::class);
});

it('issues a grant when the current password is correct', function (): void {
    $this->actingAs($this->user);

    $grant = $this->verifier->verify('update_email', ['password' => 'correct-horse-battery']);

    expect($grant)->toBeInstanceOf(Grant::class)
        ->and($grant->userId)->toBe((string) $this->user->getKey())
        ->and($grant->purpose)->toBe('update_email')
        ->and($grant->method)->toBe('password')
        ->and($this->verifier->hasValidGrant('update_email'))->toBeTrue();
});

it('rejects an incorrect password without issuing a grant', function (): void {
    $this->actingAs($this->user);

    expect(fn () => $this->verifier->verify('update_email', ['password' => 'wrong']))
        ->toThrow(ValidationException::class, __('identity-verification::messages.failed'));

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
});

it('requires the password input', function (): void {
    $this->actingAs($this->user);

    expect(fn () => $this->verifier->verify('update_email', []))
        ->toThrow(ValidationException::class);
});

it('refuses to verify when nobody is signed in, even with a real password', function (): void {
    expect(fn () => $this->verifier->verify('update_email', ['password' => 'correct-horse-battery']))
        ->toThrow(AuthenticationException::class);

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
});

it('verifies against the guard it is given', function (): void {
    config()->set('auth.guards.other', ['driver' => 'session', 'provider' => 'users']);
    $this->actingAs($this->user, 'web');

    expect(fn () => $this->verifier->verify('update_email', ['password' => 'correct-horse-battery'], guard: 'other'))
        ->toThrow(AuthenticationException::class);
});

it('rate limits failed attempts and blocks even the correct password while locked', function (): void {
    config()->set('identity-verification.rate_limit.max_attempts', 3);
    $this->actingAs($this->user);

    foreach (range(1, 3) as $attempt) {
        try {
            $this->verifier->verify('update_email', ['password' => 'wrong']);
        } catch (ValidationException) {
        }
    }

    try {
        $this->verifier->verify('update_email', ['password' => 'correct-horse-battery']);
        $this->fail('Verification should be throttled.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['password'][0])->toContain('Too many attempts');
    }

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
});

it('never stores or logs the plaintext password', function (): void {
    Log::spy();
    $this->actingAs($this->user);

    $this->verifier->verify('update_email', ['password' => 'correct-horse-battery']);

    try {
        $this->verifier->verify('update_email', ['password' => 'a-wrong-secret-value']);
    } catch (ValidationException $exception) {
        expect(json_encode($exception->errors()))->not->toContain('a-wrong-secret-value');
    }

    $cached = serialize(Cache::store()->getStore());

    expect($cached)->not->toContain('correct-horse-battery')
        ->and($cached)->not->toContain('a-wrong-secret-value');

    Log::shouldNotHaveReceived('log');
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});

it('rejects unknown verification methods and malformed purposes', function (): void {
    $this->actingAs($this->user);

    expect(fn () => $this->verifier->verify('update_email', ['password' => 'x'], method: 'carrier_pigeon'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->verifier->verify('update email; drop', ['password' => 'correct-horse-battery']))
        ->toThrow(InvalidArgumentException::class);
});
