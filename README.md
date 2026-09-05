# numra/laravel

**Numra phone checks, outcome reporting and verified webhooks for Laravel, mounted with one route macro.**

[![Packagist version](https://img.shields.io/packagist/v/numra/laravel)](https://packagist.org/packages/numra/laravel) [![Packagist downloads](https://img.shields.io/packagist/dt/numra/laravel)](https://packagist.org/packages/numra/laravel) [![licence: MIT](https://img.shields.io/packagist/l/numra/laravel)](LICENSE)

Numra for Laravel. The client, an endpoint your front end can call, and
verified webhooks — wired up with one route macro.

```bash
composer require numra/laravel
```

```dotenv
NUMRA_API_KEY=numra_live_...
NUMRA_AUTHORIZE=auth
NUMRA_WEBHOOK_SECRET=whsec_...
```

## Use the client anywhere

```php
use Numra\Numra;

public function store(Request $request, Numra $numra)
{
    $check = $numra->check($request->input('phone'));

    if ($check->isBlacklisted || $check->riskLevel === 'CRITICAL') {
        return $this->hold($order);
    }
}
```

Or via the facade: `Numra::check('0600000000')`.

Then report what happened. This is the half that gets skipped, and the half
that makes the ledger worth reading:

```php
Numra::reportOutcome([
    'phone'       => $order->phone,
    'orderId'     => $order->id,
    'outcomeType' => 'REFUSED_COD',
]);
```

## The endpoint, if you want one

Installing the package mounts **nothing**. An endpoint that exists is an
endpoint someone can call, so you say so out loud, in `routes/api.php`:

```php
Route::numra();                 // config('numra.route.prefix'), default api/numra
Route::numra('internal/risk');  // or anywhere you like
```

That mounts three named routes — `numra.check`, `numra.outcome`,
`numra.webhook` — explicitly rather than as a catch-all, so `php artisan
route:list` shows the webhook endpoint. That command is how people audit what
their app exposes; a catch-all hides it.

Then on the page:

```jsx
import { useNumraCheck, RiskBadge } from '@numra/react';

const { data, isLoading } = useNumraCheck(phone);
<RiskBadge check={data} loading={isLoading} />
```

The response has exactly the keys the React components expect, so there is no
adapter in between. And the key never leaves your server.

## `authorize` is required, and defaults to deny

Every lookup is billable, so an unguarded endpoint is an open relay pointed at
your own bill. There is no permissive default: without a rule, **every request
is refused** with a 500 that names the fix.

Three ways to set it, in order of precedence:

```php
// 1. A closure, in a service provider
NumraServiceProvider::authorizeUsing(fn (Request $r) => $r->user()?->can('take-orders'));

// 2. A Gate ability name
NUMRA_AUTHORIZE=use-numra

// 3. Shorthand for "any authenticated user"
NUMRA_AUTHORIZE=auth
```

`auth` is right for a staff dashboard and **wrong for a public checkout**,
where the guard should be a signed URL or a session that owns the cart.

A rule that throws denies. A Gate hitting a dead database must not become an
open door. A Gate ability you name but never define also denies — Laravel
returns false for an unknown one — so a typo in `NUMRA_AUTHORIZE` refuses every
request. The package logs that once, at the first lookup, rather than leaving
you to read it as a permissions problem.

Keep `NUMRA_API_KEY` in `.env` and out of version control. A key committed once
is in the history of every clone of that repository, and rotating it is the
only fix.

## Rate-limit it too

`authorize` decides who may spend your quota, not how much, and on a public
checkout those are different questions: a session that owns a cart is something
any visitor gets by loading the page, so one session in a loop is a bill.

`Route::numra()` therefore applies `throttle:60,1` to `check` and `outcome` out
of the box. This is not redundant with the `api` group — on Laravel 10 that
group included `throttle:api`, and on 11 and 12 it is empty unless your
`bootstrap/app.php` calls `->throttleApi()`, so a stock Laravel 12 store had no
limit at all. Change or remove it in the config:

```php
'route' => ['throttle' => '30,1'],   // or null to opt out
```

The webhook is left unthrottled on purpose: a 429 is a non-2xx, Numra retries a
non-2xx, and the delivery would come straight back.

Extra middleware passed to the macro is **appended**, not substituted:

```php
Route::numra(null, ['throttle:10,1']);   // api + throttle:60,1 + throttle:10,1
```

Passing one reads as "and also run this". Replacing would have answered a
request to tighten the endpoint by dropping the `api` group from it. Set
`route.middleware` in the config when you do want to replace.

## Webhooks

Set `NUMRA_WEBHOOK_SECRET` and `POST {prefix}/webhook` starts accepting
verified events. Nothing to configure for the raw body — Laravel parses JSON
lazily and never consumes the stream, so `$request->getContent()` still holds
the exact bytes the signature covers.

Each verified event is dispatched as `NumraWebhookReceived`:

```php
class HoldFlaggedOrders implements ShouldQueue   // ← make it queued
{
    public function handle(NumraWebhookReceived $event): void
    {
        if ($event->name() !== 'verification.flagged') return;

        // Numra retries on a non-2xx, and a retry carries the same envelope
        // id. De-duplicate on it.
        if (WebhookLog::whereKey($event->id())->exists()) return;

        Order::wherePhone($event->data()['phone'])->pending()->each->hold();
    }
}
```

**`ShouldQueue` is not decoration.** A listener that runs inline delays the
200, and a delivery that times out is retried — one event becomes several.

A forged signature is a **400, not a 401**: an unauthentic sender has no
credential to fix, and a 401 invites a retry storm. A timestamp outside the
300-second window is rejected as a replay, which is what stops a captured
"not blacklisted" payload staying valid for ever.

The webhook route sits outside `authorize`, and outside CSRF and the throttle
with it. Its signature is its authentication, it spends no quota, and Numra has
neither a session nor a CSRF token to offer.

## Check the credential before you ship

```bash
php artisan numra:verify
```

Prints the plan and the quota left, exits non-zero on a rejected credential,
and warns if the endpoint is mounted with no `authorize` rule. Without it the
first person to discover an expired key is a customer at checkout.

## Publish the config

```bash
php artisan vendor:publish --tag=numra-config
```

## Release notes

Every release is tagged and written up on the
[Releases page](https://github.com/NumraApp/numra-laravel/releases). The same
history in one file is in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Bug reports and patches are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) covers
running the tests, the regression test a change is expected to bring with it,
and which repository a given fix actually belongs in.

## Security

Vulnerabilities go privately to the address in [SECURITY.md](SECURITY.md).
**Do not open a public issue for a security problem** — this package mounts a
route in front of a credential that reads a shared fraud ledger, and a public
report is a working exploit for every merchant using it until a fix ships.

## The rest of the family

Twelve packages, one contract. The server side holds the API key; the browser
side calls the endpoint the server side mounts.

Server:

| Package | Repository |
|---|---|
| `@numra/core` | [numra-js-core](https://github.com/NumraApp/numra-js-core) |
| `@numra/express` | [numra-express](https://github.com/NumraApp/numra-express) |
| `@numra/fastify` | [numra-fastify](https://github.com/NumraApp/numra-fastify) |
| `@numra/next` | [numra-next](https://github.com/NumraApp/numra-next) |
| `@numra/nuxt` | [numra-nuxt](https://github.com/NumraApp/numra-nuxt) |
| `numra/numra-php` | [numra-php](https://github.com/NumraApp/numra-php) |
| `numra/laravel` | [numra-laravel](https://github.com/NumraApp/numra-laravel) — this repo |

Browser:

| Package | Repository |
|---|---|
| `@numra/browser` | [numra-browser](https://github.com/NumraApp/numra-browser) |
| `@numra/react` | [numra-react](https://github.com/NumraApp/numra-react) |
| `@numra/vue` | [numra-vue](https://github.com/NumraApp/numra-vue) |
| `@numra/svelte` | [numra-svelte](https://github.com/NumraApp/numra-svelte) |
| `@numra/angular` | [numra-angular](https://github.com/NumraApp/numra-angular) |

Documentation for all of them is at [numra.ma/docs](https://numra.ma/docs).

## Licence

MIT
