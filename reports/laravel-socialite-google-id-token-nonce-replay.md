# Laravel Socialite Google ID tokens are accepted without checking the OIDC nonce

**Package:** `laravel/socialite`  
**Version tested:** `v5.32.0`  
**Area:** Google provider, ID-token authentication through `userFromToken()`  
**Researcher:** `djokage`  
**Status:** Privately reported to Laravel on 16 November 2026. Laravel acknowledged receipt and said the report was under review. No maintainer confirmation, fix, or CVE assignment is claimed here.

## Summary

Socialite's Google provider verifies the signature and several standard claims in a Google ID token, but it does not compare the token's `nonce` claim with the value used for the login attempt.

This matters when an application uses `userFromToken()` to authenticate a local user from a Google ID token. The application can put a fresh nonce in the Google authorization request and keep that value in the browser session, but the Google provider does not expose the nonce check that is present in Socialite's Facebook Limited Login implementation. If the application trusts the returned Socialite user without doing the comparison itself, a valid token from a different login attempt is accepted.

I reproduced this through a real Laravel HTTP application using the installed Socialite package. A token created for one browser session was accepted in another session that had a different nonce, and the second session remained authenticated on the next request.

## Affected code

In `GoogleProvider::getUserByToken()`, a JWT-shaped token is sent to `getUserFromJwtToken()`. That method decodes the token using Google's JWKS and checks the issuer and audience. Firebase JWT also performs signature and time validation in this path. The method then returns the claims without checking `nonce`.

Sources:

- [GoogleProvider in v5.32.0](https://github.com/laravel/socialite/blob/v5.32.0/src/Two/GoogleProvider.php#L50-L66)
- [AbstractProvider::userFromToken() in v5.32.0](https://github.com/laravel/socialite/blob/v5.32.0/src/Two/AbstractProvider.php#L264-L275)

`AbstractProvider::userFromToken()` accepts only the token, calls the provider's `getUserByToken()`, and maps the returned claims to a Socialite user. It has no expected-nonce argument.

The generic `with(['nonce' => $value])` parameter is included in the authorization URL. It does not make the Google provider compare the returned claim with the application's stored value.

There is already a nonce-aware implementation in the same package. `FacebookProvider::userFromToken($token, $nonce)` stores the expected nonce and rejects an OIDC token when the expected value is absent, the token claim is missing, or the values do not match. The Google provider has no equivalent check.

- [FacebookProvider OIDC validation](https://github.com/laravel/socialite/blob/v5.32.0/src/Two/FacebookProvider.php#L112-L167)
- [FacebookProvider::withNonce()](https://github.com/laravel/socialite/blob/v5.32.0/src/Two/FacebookProvider.php#L298-L318)

## Reproduction

The test was run against the actual installed `Laravel\\Socialite\\Two\\GoogleProvider`, resolved through Laravel's registered Socialite manager. It uses Laravel's real HTTP entry point, web middleware, encrypted cookies, CSRF verification, file-backed sessions, authentication guard, and subsequent authenticated requests.

The test does not contact Google. It creates an RSA test key and a matching local JWKS, then substitutes only Google's JWKS HTTP response using Guzzle's `MockHandler`. The actual Socialite provider and Firebase JWT library perform token verification. A test-only file-backed Laravel user provider keeps the synthetic user available across HTTP requests; the SQL database is not involved.

The test flow:

1. Browser A starts a Google login. The application stores nonce A in its session and Socialite includes it in the authorization URL.
2. A correctly signed ID token containing nonce A is submitted through the Laravel callback. Authentication succeeds.
3. Browser B starts a separate login and receives nonce B.
4. Browser B submits the token from A through the same callback path. Socialite returns the user even though nonce A and nonce B differ.
5. A later request carrying B's own session cookie is still authenticated as the account represented by the token.
6. A control callback that compares the token claim with the session nonce rejects the same replay before logging in the user.

Recorded result: **17 assertions passed, 0 failed.** The negative controls reject an invalid signature, wrong audience, wrong issuer, and expired token. A token with no nonce is accepted by the unchecked application path and creates a persistent local session.

The complete recorded output is in [reports/evidence/socialite-google-nonce-17-assertions.txt](evidence/socialite-google-nonce-17-assertions.txt).

## Impact and scope

The demonstrated behavior affects applications that:
- use Google's ID-token path through `GoogleProvider::userFromToken()`;
- send a nonce in the login request and retain the expected value for that login attempt; and
- use the returned Socialite identity to create a local authenticated session without independently checking the ID token's nonce.

In that configuration, a valid ID token from a different login attempt can be accepted in the current browser session. Depending on how the application receives and handles tokens, this can result in login-session substitution or contribute to an ID-token replay attack.

This is not a finding that every Laravel application using Socialite is vulnerable. The normal Google authorization-code callback through `user()` was not the path tested. Applications that compare the returned nonce against the value stored for the current login attempt before calling `Auth::login()` are protected against the demonstrated mismatch.

The reproduction does not establish how an attacker obtains another user's Google ID token or how an attacker could make a victim's browser submit a token across sessions. The cross-session test deliberately uses browser B's own valid session cookie and CSRF token. It therefore does not demonstrate a CSRF bypass or a complete remote exploit. Impact depends on the target application's token source, callback design, and other protections.

## Expected behavior

When an application has sent a nonce in the OpenID Connect authentication request, the relying party must compare the nonce claim in the returned ID token with the value from that request before using the token to authenticate the user. OpenID Connect Core describes the nonce as a value that binds a client session to an ID token and requires clients to verify that the claim matches the request value.

- [OpenID Connect Core 1.0 — ID Token nonce claim](https://openid.net/specs/openid-connect-core-1_0.html#IDToken)
- [OpenID Connect Core 1.0 — ID Token validation](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation)

The Google provider should offer an explicit expected-nonce validation path for ID tokens, comparable to the existing Facebook provider API. It should reject a missing or mismatched nonce when one is expected, before returning a user object that an application may use to establish a session.

As a workaround, applications using this path should retain the nonce in the current login session and compare it with the nonce claim returned by the ID token before authenticating the local user. Use a constant-time comparison such as `hash_equals()`.

## Test environment

- Laravel Framework `13.42.0`
- Laravel Socialite `v5.32.0` (`d766b8b85c4fd77eb1643455e12a80a2fb9b28b7`)
- Firebase JWT `v7.2.1` (`0501f5729b9e2448516674aff07c7d1727869b8e`)
- PHP `8.4.24`

The test uses generated local keys; no Google credentials or production signing keys are included.

## Disclosure

I sent a private report to Laravel on 16 November 2026. Laravel acknowledged receipt and said the findings were under review. That response did not confirm the issue, assign a CVE, or provide a fix timeline. This report is being published before the maintainer review has concluded. Laravel Socialite's published security policy asks researchers not to disclose security issues publicly.
