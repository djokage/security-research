# Livewire `#[Json]` methods run before global authorization hooks

A global Livewire `ComponentHook::call()` can reject a component action, but it runs too late to stop a method marked with `#[Json]`.

In Livewire 4.4.8, the JSON method runs first. The global hook then rejects the request and Livewire returns HTTP 403. The 403 response does not undo side effects that already happened inside the method.

## What happens

The order of the `call` listeners causes the problem:

1. `SupportJson` registers a listener for component calls.
2. When the listener receives a call to a `#[Json]` method, it executes that method.
3. `ComponentHookRegistry` dispatches the call to application-defined global hooks afterward.
4. The custom hook rejects the call, but the JSON method has already run.

Livewire 4.4.8 checks the built-in `BaseAuthorize` attributes before executing a JSON method. It does not run application-defined global `ComponentHook::call()` hooks at that point.

Relevant source:

- [SupportJson.php](https://github.com/livewire/livewire/blob/v4.4.8/src/Features/SupportJson/SupportJson.php)
- [ComponentHookRegistry.php](https://github.com/livewire/livewire/blob/v4.4.8/src/ComponentHookRegistry.php)
- [LivewireServiceProvider.php](https://github.com/livewire/livewire/blob/v4.4.8/src/LivewireServiceProvider.php)
- [EventBus.php](https://github.com/livewire/livewire/blob/v4.4.8/src/EventBus.php)
- [Global Component Hooks documentation](https://github.com/livewire/livewire/blob/4.x/docs/component-hooks.md)

## Reproduction

I tested this with a Laravel application using Livewire's test request machinery. A global hook rejects selected action names. The first test methods write a marker when their method body runs.

Environment:

- Livewire 4.4.8
- Laravel Framework 13.42.0
- PHP 8.4.24

Output:

```text
json_http_status=403
json_side_effect=json
builtin_attribute_http_status=403
builtin_attribute_side_effect=absent
ordinary_http_status=403
ordinary_side_effect=absent
```

The JSON method writes its marker before the global hook rejects the request. The built-in `#[Authorize]` check and the global hook on an ordinary action both stop the method before its side effect.

## Database-backed cross-user test

I then tested the behavior through Laravel's HTTP kernel with authenticated sessions and a separate SQLite database.

The database contained two users:

- User 1: authenticated non-admin account.
- User 2: administrator and owner of the target record.

The non-admin called a `#[Json]` method that updated the target record by ID. The application relied on the global component hook to reject the action. The method body updated the row before that hook rejected the request.

Results:

| Test | HTTP status | Record result |
|---|---:|---|
| Non-admin JSON action, targeting admin-owned record | 403 | Modified by user 1 |
| Non-admin ordinary action, same record | 403 | Unchanged |
| Non-admin JSON with inline `Gate::authorize()` | 403 | Unchanged |
| Non-admin JSON with built-in `#[Authorize]` | 403 | Unchanged |
| Admin JSON action | 200 | Modified by user 2 |
| Non-admin JSON request without CSRF token | 419 | Unchanged |

The database proof recorded:

```text
authenticated_session_user_id=1
target_record_owner_user_id=2
http_response_status=403
database_value=modified-by-member
database_changed_by_user_id=1

CROSS-USER DATABASE PROOF PASSED
```

The test also persisted a per-case audit row in the SQLite database. The complete output is in [livewire-cross-user-db-proof.txt](evidence/livewire-cross-user-db-proof.txt).

This demonstrates a cross-user database modification in the test application: user 1 changed a record owned by user 2, even though the global hook subsequently rejected the request with HTTP 403.

The inline `Gate::authorize()` control is important. When the method performs its own authorization before updating the row, the record remains unchanged. The built-in `#[Authorize]` control also prevented the write.

## Impact

An authenticated user may cause a `#[Json]` method to perform an unauthorized operation when the application relies on a global `ComponentHook::call()` as the only authorization check for that method.

The database test demonstrates cross-user modification of a record owned by another account. The request still returns HTTP 403, but that response does not reverse the database change.

This is not a general bypass of Laravel authorization. A method that performs its own authorization before a sensitive operation, for example with `Gate::authorize()` or the tested built-in `#[Authorize]` attribute, blocked the modification.

The issue is relevant to applications that use global component hooks as their authorization boundary for `#[Json]` methods and do not repeat the required authorization inside those methods.

## Relation to the existing Livewire advisory

[GHSA-mhf7-xp6r-6qrc](https://github.com/livewire/livewire/security/advisories/GHSA-mhf7-xp6r-6qrc) covers the `#[Authorize]` authorization path for JSON methods and was fixed in Livewire 4.4.8.

This report covers application-defined global `ComponentHook::call()` authorization. In the tested 4.4.8 implementation, the built-in `BaseAuthorize` check runs before the JSON method, while the global component-hook listener runs afterward.

## Workaround

Do not rely only on a global component hook to protect a `#[Json]` method. Check authorization inside the method, before performing any sensitive operation. The tested inline `Gate::authorize()` and built-in `#[Authorize]` controls both prevented the database write.

## Version tested

The behavior was reproduced on Livewire 4.4.8 with Laravel Framework 13.42.0 and PHP 8.4.24. The full affected-version range has not been tested.

## Expected behavior

Global authorization hooks should run before a `#[Json]` method is executed. A rejected call must not enter the method body or perform its side effects.
