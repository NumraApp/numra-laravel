# Contributing to numra/laravel

Patches are welcome. This package mounts a route in front of a credential that
reads a shared fraud ledger and spends a merchant's paid quota, so the bar for
a change is a test that would have caught the bug, not a convincing
description of it.

## Running the tests

```bash
composer install && vendor/bin/phpunit
```

PHP 8.1 or newer. The suite runs on Orchestra Testbench, so it boots a real
Laravel application container rather than mocking one — that is what makes
`OptInTest` able to assert the middleware actually attached to `numra.check`,
rather than the middleware someone meant to attach.
`tests/FakeTransport.php` stands in for the network, so nothing reaches the
API and no key is needed.

The release pipeline runs the suite on PHP 8.1, 8.2, 8.3 and 8.4, and the
package declares support for Laravel 10, 11 and 12. A change that quietly
depends on one of those will fail there rather than in a merchant's upgrade.

## Every change needs a test

Every package in this family ships a regression suite, and it is the only
thing standing between a refactor and a silent behavioural change. So:

- A bug fix comes with a test that fails before it and passes after.
- A new config key or macro argument comes with a test that exercises it.
- A change to existing behaviour comes with the changed assertion, and the
  reason for the change in the commit message.

`tests/OptInTest.php` and `tests/RouteTest.php` hold the decisions that are
easiest to undo by accident and worst to get wrong:

- **Installing the package mounts nothing.** The route exists only where
  someone wrote `Route::numra()`, and extra middleware passed to the macro is
  appended rather than substituted — so a call meant to tighten the endpoint
  cannot end up loosening it.
- **No `authorize` rule means every request is refused**, a rule that throws
  fails closed, and a rejected credential never reaches the browser as a 401.
- The billable routes are throttled and the webhook is not, a forged signature
  is a 400 that dispatches nothing, and a stale timestamp is rejected as a
  replay.

## Which repository your fix belongs in

These repositories are split out of a single monorepo. What you see here is
one package of twelve, and this one is a thin layer over
[numra-php](https://github.com/NumraApp/numra-php).

So:

- Anything about the client itself, the request lifecycle, retries, or
  webhook verification belongs in **`numra/numra-php`**, not here.
- Anything about how a request is authorised, narrowed for the browser, or
  translated when upstream fails belongs in `Numra\Handlers`, also in
  `numra/numra-php` — and that class is the twin of `createHandlers` in
  [numra-js-core](https://github.com/NumraApp/numra-js-core), so a change to
  what it decides needs the same change there.
- Anything Laravel-shaped — the service provider, the route macro, the facade,
  the config file, the event, `php artisan numra:verify` — belongs here.

## Versions and tags

`numra/numra-php` must be tagged and on Packagist **before** this package is
tagged. There is no path repository: `composer install` here resolves
`numra/numra-php` from Packagist and nowhere else, so tagging this one first
gives every installer an unsolvable requirement.

## The conformance gate

```bash
node scripts/openapi-conformance.js
```

Node is used only to run this one release gate; the package itself needs
nothing but PHP. It checks the package against the API contract and against
itself, and fails by default when no contract is vendored, on purpose: a
conformance step that goes green having compared nothing manufactures exactly
the assurance it exists to provide. Point `NUMRA_OPENAPI` at a copy of the
spec, or drop it at one of the paths the script lists, to make it run for
real.

## House style

British spelling, no emoji in headings, and prose that says what a thing does
rather than how good it is. Comments explain the decision, not the syntax.

## Reporting a bug

Open an issue with the package version, the Laravel version, the PHP version,
and the smallest reproduction you can manage. **A security vulnerability is
not a bug report** — see [SECURITY.md](SECURITY.md) and mail it privately
instead.
