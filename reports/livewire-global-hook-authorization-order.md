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

I tested this with a Laravel application using Livewire's `Livewire::test()` request machinery. A global hook rejects selected action names. The test methods write a marker to a local file when their method body runs.

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

## Impact

An attacker who can send a valid Livewire update can trigger a `#[Json]` method even when the application's global hook rejects that action.

The consequence depends on the method. It can include unauthorized database changes, deletion, or other application-side operations.

This affects applications that rely on a global `ComponentHook::call()` as the only authorization check for a `#[Json]` method. It is not a general bypass of Laravel authorization, and it does not bypass the built-in `#[Authorize]` check demonstrated above.

## Relation to the existing Livewire advisory

[GHSA-mhf7-xp6r-6qrc](https://github.com/livewire/livewire/security/advisories/GHSA-mhf7-xp6r-6qrc) covers the `#[Authorize]` authorization path for JSON methods and was fixed in Livewire 4.4.8.

This report covers application-defined global `ComponentHook::call()` authorization. The 4.4.8 code checks `BaseAuthorize` attributes before invoking the JSON method, but the global component-hook listener still runs afterward. The reproduction shows that this path can still perform a side effect before returning 403.

## Workaround

Do not rely only on a global component hook to protect a `#[Json]` method. Check authorization inside the method, before performing any sensitive operation. The built-in `#[Authorize]` attribute is also checked before the JSON method runs in the tested version.

## Version tested

The behavior was reproduced on Livewire 4.4.8. The full affected-version range has not been tested.

## Expected behavior

Global authorization hooks should run before a `#[Json]` method is executed. A rejected call must not enter the method body or perform its side effects.
