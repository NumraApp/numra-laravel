<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credential
    |--------------------------------------------------------------------------
    |
    | Server-side only. This key reads a shared fraud ledger, so it must never
    | reach a Blade view, a JS bundle, or anything the browser can see. Keep it
    | in .env and out of version control.
    |
    */

    'api_key' => env('NUMRA_API_KEY'),

    'base_url' => env('NUMRA_BASE_URL', 'https://api.numra.ma'),

    'timeout' => (float) env('NUMRA_TIMEOUT', 10),

    'max_retries' => (int) env('NUMRA_MAX_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | The endpoint your front end calls
    |--------------------------------------------------------------------------
    |
    | Registered by Route::numra(). Nothing is mounted unless you call it, so a
    | project that only uses the client from a job or a controller never
    | exposes a route it did not ask for.
    |
    */

    'route' => [
        'prefix' => env('NUMRA_ROUTE_PREFIX', 'api/numra'),

        'middleware' => ['api'],

        /*
        | Applied to check and outcome, which spend your quota. Not to the
        | webhook: a 429 is a non-2xx, Numra retries a non-2xx, and throttling
        | deliveries would turn a busy hour into a redelivery backlog.
        |
        | This is not redundant with the 'api' group. On Laravel 10 that group
        | included throttle:api; on 11 and 12 it is empty unless the app called
        | ->throttleApi() in bootstrap/app.php. A stock Laravel 12 install
        | mounting these routes therefore has no limit at all, on an endpoint
        | whose every call is billed to you.
        |
        | 60 a minute per client is far above a real checkout and still bounds
        | what a script can spend. Set it to null to opt out.
        */
        'throttle' => env('NUMRA_ROUTE_THROTTLE', '60,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Who may spend your quota
    |--------------------------------------------------------------------------
    |
    | Every lookup is billable, so an unguarded endpoint is an open relay
    | pointed at your own bill. There is no permissive default: leave this null
    | and every request is refused with a 500 that tells you what to write.
    |
    | Give it a Gate ability name ('use-numra'), or set a closure in a service
    | provider:
    |
    |     Numra::authorizeUsing(fn (Request $r) => (bool) $r->user());
    |
    | 'auth' is shorthand for "any authenticated user" — correct for a staff
    | dashboard, wrong for a public checkout, where the guard should be a
    | signed URL or a session that owns the cart.
    |
    */

    'authorize' => env('NUMRA_AUTHORIZE'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Set the secret and POST {prefix}/webhook starts accepting verified
    | events, each dispatched as Numra\Laravel\Events\NumraWebhookReceived.
    |
    | Make your listener implement ShouldQueue. Numra retries on a non-2xx, so
    | a listener that runs inline and takes too long turns one delivery into
    | several.
    |
    */

    'webhook_secret' => env('NUMRA_WEBHOOK_SECRET'),

];
