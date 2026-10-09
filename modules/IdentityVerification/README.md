# Identity verification

Asks an already signed-in user to prove who they are again (for now: re-enter their current password) before a sensitive operation runs, such as changing their email address or deleting their account.

A successful verification issues a **grant**: a short-lived, single-use, server-side record bound to the user, the auth guard, the current session and one **purpose** (for example `update_email`). The protected operation spends the grant on the server immediately before it runs. Nothing the browser sends can create, extend or redirect a grant.

## Layout

```
modules/IdentityVerification/
├── config/identity-verification.php     grant TTL, rate limit, methods, cache store
├── lang/en/messages.php
├── src/
│   ├── IdentityVerifier.php              verify(), hasValidGrant(), consume(), revokeAll()
│   ├── VerificationMethod.php            contract for verification methods
│   ├── Methods/PasswordVerificationMethod.php
│   ├── Grant.php                         value object
│   ├── GrantStore.php / CacheGrantStore.php
│   ├── Exceptions/IdentityVerificationRequired.php   (HTTP 403)
│   ├── Http/Middleware/RequireIdentityVerification.php   alias: identity.verified
│   ├── IdentityVerificationServiceProvider.php
│   └── Filament/                         the only code that knows about Filament
│       ├── IdentityVerificationAction.php
│       ├── Contracts/HasIdentityVerification.php
│       └── Concerns/RequiresIdentityVerification.php, InteractsWithIdentityVerification.php
└── tests/
```

Everything outside `src/Filament` is plain Laravel and can be used from controllers, jobs or other UIs.

## Protecting a Filament action

1. Make the action a class that implements `HasIdentityVerification` and uses `RequiresIdentityVerification`:

   ```php
   class DeleteAccountAction extends Action implements HasIdentityVerification
   {
       use RequiresIdentityVerification;

       protected function setUp(): void
       {
           parent::setUp();

           $this->requiresIdentityVerification(purpose: 'delete_account')
               ->authorize('delete')
               ->requiresConfirmation()
               ->action(fn (User $record) => app(DeleteAccount::class)->handle($record));
       }
   }
   ```

   You can also set or override it where the action is used:

   ```php
   UpdateEmailAction::make()->requiresIdentityVerification(purpose: 'update_email', method: 'password');
   ```

2. Add `InteractsWithIdentityVerification` to the page (or Livewire component) that hosts the action. It registers the verification modal at the root of the component.

That's all. See `app/Filament/Pages/AccountSettings.php` and `app/Filament/Actions/UpdateEmailAction.php` for a working example.

### What happens

1. The user clicks the action. Its `mount()` sees there is no grant and swaps it for the verification modal with `replaceMountedAction()`. The protected form is never filled or rendered.
2. A wrong password keeps the modal open with an error, clears the field and counts towards the rate limit.
3. A correct password issues a grant for the action's purpose and swaps the modal back to the protected action, with its original arguments and context.
4. On submit, the action's own validation runs first. Only once the form is valid is the grant spent (in `callBefore()`, before any `before()` hook, with a fallback in `call()`). Then the action's logic runs.
5. Cancelling at any point runs nothing. An unused grant expires on its own.

### Why this is not UI-only

`mountedActions` is a public Livewire property, and `mountAction()` / `callMountedAction()` are public Livewire methods, so a crafted request can mount or call any action directly. The check therefore lives in the action's execution path on the server, not in the modal flow:

- The grant is spent in the overridden `callBefore()` / `call()`, so it runs no matter how the request got there and no matter in which order `->action()`, `->fillForm()`, etc. were chained.
- The purpose, method and guard always come from the protected action's server-side definition. The verification modal's arguments only name the action to return to; changing them can only point at another protected action, which then gets its own purpose.
- Filament's normal authorization (`authorize()`, policies, visibility) runs before the grant is checked, and independently of it. A grant never grants permission.
- Every grant check compares user, guard, session, purpose, expiry and revocation generation.

### Limits

- A protected action must be mounted at the root: header, page, table row, or schema component action. `replaceMountedAction()` clears the whole stack, so a protected action nested in another action's modal would lose its parents. This throws a `LogicException` rather than failing silently.
- The purpose is a fixed string per action. If a grant must be bound to one record, encode that in the purpose (for example `delete_user:{$id}`) from server-side data, never from action arguments.
- Grants are bound to the session, so they do not carry across browsers or devices, by design.

## Protecting other operations (no Filament)

Route middleware, which spends the grant before the controller runs:

```php
Route::delete('/account', DestroyAccountController::class)
    ->middleware(['auth', 'identity.verified:delete_account']);
```

Or call the verifier yourself right before the sensitive work:

```php
app(IdentityVerifier::class)->consume('delete_account'); // throws IdentityVerificationRequired (403)
```

To issue a grant from your own form or endpoint:

```php
app(IdentityVerifier::class)->verify('delete_account', $request->only('password'));
// Throws ValidationException (keyed by input name) on bad input, wrong
// credentials or too many attempts; AuthenticationException when signed out.
```

## Adding a verification method

1. Implement the contract. Keep the method's own validation and security rules inside it:

   ```php
   final class EmailCodeVerificationMethod implements VerificationMethod
   {
       public function rules(): array
       {
           return ['code' => ['required', 'digits:6']];
       }

       public function verify(Authenticatable $user, array $input, string $guard): bool
       {
           // compare against a hashed, expiring code you sent earlier
       }
   }
   ```

2. Register it under a name, either in `config/identity-verification.php` → `methods`, or at runtime:

   ```php
   app(IdentityVerifier::class)->extend('email_code', EmailCodeVerificationMethod::class);
   ```

3. Tell the Filament modal which fields to show (in a service provider's `boot()`):

   ```php
   IdentityVerificationAction::registerMethodSchema('email_code', fn (): array => [
       TextInput::make('code')->required()->length(6),
   ]);
   ```

4. Use it: `->requiresIdentityVerification(purpose: 'delete_account', method: 'email_code')`.

Grant storage, expiry, single use, rate limiting and enforcement stay the same for every method.

## Configuration

Publish with `php artisan vendor:publish --tag=identity-verification-config`.

| Key | Default | |
|---|---|---|
| `grant_ttl` | `300` | Seconds a grant stays usable after verification. |
| `rate_limit.max_attempts` / `decay_seconds` | `5` / `60` | Failed attempts per user and guard before verification locks. |
| `cache_store` | default store | Must support atomic `add()`: redis, memcached, database, dynamodb or file. The array store is only atomic within one process. |
| `guard` | `auth.defaults.guard` | Filament always passes the panel's guard. |
| `methods` / `default_method` | `password` | Registered verification methods. |

## Revocation

Grants are revoked for every guard and session when:

- the user's password column changes, for any Eloquent user model configured as an auth provider
- `Illuminate\Auth\Events\PasswordReset` is dispatched
- `Illuminate\Auth\Events\OtherDeviceLogout` is dispatched

They also become unusable when the session is invalidated or regenerated, for example at login or logout.

## Tests

```
php artisan test --testsuite=Modules
```

The suite covers password verification, rate limiting, grant ownership, purpose binding, expiry, replay and revocation. It also covers a real multi-process race on a shared database cache store, the Filament modal flow, and bypass attempts made by writing `mountedActions` directly.
