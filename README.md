# Project skeleton

Laravel 12 with a Filament 5 admin panel (`/admin`), Livewire 4 and Pest.

## Setup

```bash
composer update
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan make:filament-user
```

There is no `composer.lock` yet; `composer update` creates one, and it should be committed afterwards. The stack was built and tested against Filament 5.10.1, Laravel 12.69.2 and Livewire 4.4.7.

## Modules

Self-contained features live in `modules/<Name>/`, each with its own `src/`, `config/`, `lang/`, `tests/` and service provider (registered in `bootstrap/providers.php`).

- [`IdentityVerification`](modules/IdentityVerification/README.md): asks users to re-confirm their identity before sensitive actions. The *Account settings* page in the panel uses it for changing the email address.

## Checks

```bash
php artisan test               # all suites, including modules/*/tests
vendor/bin/pint --test
vendor/bin/phpstan analyse     # larastan, level 6
```
