<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Modules\IdentityVerification\IdentityVerifier;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'identity.verified:delete_account'])
        ->post('/_test/delete-account', fn () => response('deleted'));

    $this->user = User::factory()->create(['password' => 'correct-horse-battery']);

    // Grants are bound to the session, so requests must reuse the test's session.
    $this->withoutMiddleware(VerifyCsrfToken::class)
        ->withCookie(config('session.cookie'), app('session.store')->getId());
});

it('rejects the route without a grant', function (): void {
    $this->actingAs($this->user)
        ->post('/_test/delete-account')
        ->assertForbidden();
});

it('allows the route once with a grant for the same purpose', function (): void {
    $this->actingAs($this->user);
    app(IdentityVerifier::class)->verify('delete_account', ['password' => 'correct-horse-battery']);

    $this->post('/_test/delete-account')
        ->assertOk()
        ->assertSee('deleted');

    $this->post('/_test/delete-account')->assertForbidden();
});

it('rejects a grant issued for another purpose', function (): void {
    $this->actingAs($this->user);
    app(IdentityVerifier::class)->verify('update_email', ['password' => 'correct-horse-battery']);

    $this->post('/_test/delete-account')->assertForbidden();
});
