<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\IdentityVerification\CacheGrantStore;
use Modules\IdentityVerification\Exceptions\IdentityVerificationRequired;
use Modules\IdentityVerification\GrantStore;
use Modules\IdentityVerification\IdentityVerifier;

beforeEach(function (): void {
    $this->user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->verifier = app(IdentityVerifier::class);
    $this->actingAs($this->user);
});

function verifyAs(string $purpose = 'update_email'): void
{
    app(IdentityVerifier::class)->verify($purpose, ['password' => 'correct-horse-battery']);
}

it('consumes a grant exactly once', function (): void {
    verifyAs();

    $grant = $this->verifier->consume('update_email');

    expect($grant->purpose)->toBe('update_email')
        ->and($this->verifier->hasValidGrant('update_email'))->toBeFalse();

    expect(fn () => $this->verifier->consume('update_email'))
        ->toThrow(IdentityVerificationRequired::class);
});

it('only authorizes the purpose it was issued for', function (): void {
    verifyAs('update_email');

    expect($this->verifier->hasValidGrant('delete_account'))->toBeFalse();
    expect(fn () => $this->verifier->consume('delete_account'))
        ->toThrow(IdentityVerificationRequired::class);

    expect($this->verifier->hasValidGrant('update_email'))->toBeTrue();
});

it('only belongs to the user who verified', function (): void {
    verifyAs();

    $this->actingAs(User::factory()->create());

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
    expect(fn () => $this->verifier->consume('update_email'))
        ->toThrow(IdentityVerificationRequired::class);
});

it('is rejected once it expires', function (): void {
    config()->set('identity-verification.grant_ttl', 120);
    verifyAs();

    $this->travel(119)->seconds();
    expect($this->verifier->hasValidGrant('update_email'))->toBeTrue();

    $this->travel(2)->seconds();
    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
    expect(fn () => $this->verifier->consume('update_email'))
        ->toThrow(IdentityVerificationRequired::class);
});

it('is bound to the session it was issued in', function (): void {
    verifyAs();

    app('session.store')->migrate();

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
});

it('is revoked when the password changes', function (): void {
    verifyAs();

    $this->user->update(['password' => 'a-brand-new-password']);

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
});

it('is not revoked by unrelated profile changes', function (): void {
    verifyAs();

    $this->user->update(['name' => 'Someone Else']);

    expect($this->verifier->hasValidGrant('update_email'))->toBeTrue();
});

it('is revoked when a password reset happens', function (): void {
    verifyAs();

    event(new PasswordReset($this->user));

    expect($this->verifier->hasValidGrant('update_email'))->toBeFalse();
});

it('lets only one of two interleaved requests consume the same grant', function (): void {
    verifyAs();
    $store = app(GrantStore::class);
    $userId = (string) $this->user->getKey();
    $session = hash('sha256', app('session.store')->getId());

    // Both "requests" read the grant before either spends it.
    $first = $store->find('web', $userId, 'update_email', $session);
    $second = $store->find('web', $userId, 'update_email', $session);

    expect($first)->not->toBeNull()
        ->and($store->consume($first))->toBeTrue()
        ->and($store->consume($second))->toBeFalse();
});

it('lets only one of many concurrent processes consume the same grant', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to race real processes.');
    }

    // A file-backed database cache store that separate processes really share.
    $database = tempnam(sys_get_temp_dir(), 'iv-race-').'.sqlite';
    touch($database);
    config()->set('database.connections.race', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10000,
        'journal_mode' => 'wal',
    ]);
    config()->set('cache.stores.race', ['driver' => 'database', 'connection' => 'race', 'table' => 'cache']);
    Schema::connection('race')->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration')->index();
    });

    app()->instance(GrantStore::class, new CacheGrantStore(Cache::store('race')));
    app()->forgetInstance(IdentityVerifier::class);
    verifyAs();

    $store = app(GrantStore::class);
    $grant = $store->find('web', (string) $this->user->getKey(), 'update_email', hash('sha256', app('session.store')->getId()));
    expect($grant)->not->toBeNull();

    $results = tempnam(sys_get_temp_dir(), 'iv-race-results-');
    DB::disconnect('race');

    $children = [];
    foreach (range(1, 8) as $ignored) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge('race');
            Cache::forgetDriver('race');
            $won = (new CacheGrantStore(Cache::store('race')))->consume($grant);
            file_put_contents($results, ($won ? 'W' : 'L'), FILE_APPEND | LOCK_EX);
            posix_kill(getmypid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $outcomes = (string) file_get_contents($results);

    expect(strlen($outcomes))->toBe(8)
        ->and(substr_count($outcomes, 'W'))->toBe(1);

    @unlink($results);
    @unlink($database);
});
