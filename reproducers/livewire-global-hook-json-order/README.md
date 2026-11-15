# Livewire `#[Json]` and global `ComponentHook::call()` authorization order

Minimal Laravel reproduction for the behavior reported in [djokage/security-research](https://github.com/djokage/security-research/blob/main/reports/livewire-global-hook-authorization-order.md).

The fixture registers a global component hook in `AppServiceProvider::register()`. The hook rejects non-admin calls to selected component methods. A `#[Json]` method updates a SQLite-backed row before the global hook rejects the call, while an ordinary method is rejected before its body runs. Inline Gate authorization and the built-in `#[Authorize]` attribute are included as controls.

## Tested versions

The original integration evidence in the report was collected with:

- PHP 8.4.24
- Laravel Framework 13.42.0
- Livewire 4.4.8

This is a focused PHPUnit/Livewire test harness. The repository's separate `reports/evidence/livewire-cross-user-db-proof.txt` contains the recorded Laravel HTTP-kernel session/CSRF run.

## Run it

Requirements: PHP 8.4, Composer 2, and Laravel's required PHP extensions, including `pdo_sqlite` and `sqlite3`.

1. Create a clean Laravel application:

   ```bash
   composer create-project laravel/laravel:^13.0 livewire-hook-repro
   cd livewire-hook-repro
   ```

2. Pin the tested framework and Livewire versions:

   ```bash
   composer require laravel/framework:13.42.0 livewire/livewire:4.4.8 --with-all-dependencies
   ```

3. Clone this research repository in another directory, then install the fixture into the fresh Laravel application:

   ```bash
   git clone https://github.com/djokage/security-research.git
   bash security-research/reproducers/livewire-global-hook-json-order/install.sh "$PWD"
   ```

   Run the install script from the Laravel application directory. It backs up the default `AppServiceProvider.php` before replacing it with the fixture provider.

4. Run the tests:

   ```bash
   php artisan test tests/Feature/GlobalHookJsonAuthorizationTest.php
   ```

## Expected behavior in the fixture

The test suite asserts:

- A non-admin call to `#[Json] updateJson()` returns 403, but the admin-owned database row records the non-admin as the changer.
- A non-admin ordinary action returns 403 and does not update the row.
- Inline `Gate::authorize()` blocks the JSON method before the write.
- The built-in `#[Authorize]` attribute blocks the JSON method before the write.
- An administrator can call the JSON method successfully.

The finding is specifically about applications that rely on a global `ComponentHook::call()` as the only authorization boundary for a `#[Json]` method. This is not a general Laravel authorization bypass. Applications should authorize sensitive operations inside the method before performing them.

## Source locations in Livewire v4.4.8

- [`SupportJson.php`](https://github.com/livewire/livewire/blob/v4.4.8/src/Features/SupportJson/SupportJson.php): the `call` listener executes the JSON method and invokes `$returnEarly()`.
- [`ComponentHookRegistry.php`](https://github.com/livewire/livewire/blob/v4.4.8/src/ComponentHookRegistry.php): registers the global `call` listener that dispatches to component hooks.
- [`HandleComponents.php`](https://github.com/livewire/livewire/blob/v4.4.8/src/Mechanisms/HandleComponents/HandleComponents.php): `callMethods()` triggers the `call` event, then handles an early return and runs the collected finishing callbacks.

## Cleanup

This fixture is intended for a clean, disposable Laravel project. To restore the provider that existed before installation:

```bash
cp app/Providers/AppServiceProvider.php.before-livewire-global-hook-repro \
   app/Providers/AppServiceProvider.php
```

The tests use Laravel's test database and `RefreshDatabase`; they do not connect to a production database.
