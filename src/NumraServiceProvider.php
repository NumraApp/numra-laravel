<?php

declare(strict_types=1);

namespace Numra\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Numra\Handlers;
use Numra\Numra;
use Numra\Laravel\Console\VerifyCommand;
use Numra\Laravel\Http\NumraController;

/**
 * Wires the PHP client into a Laravel app.
 *
 * Nothing is routed unless the app calls `Route::numra()`. A project that only
 * uses the client from a job or a controller never gets an endpoint it did not
 * ask for — and an endpoint that exists is an endpoint someone can call.
 */
final class NumraServiceProvider extends ServiceProvider
{
    /** Read by scripts/stamp-sdk-manifest.js, so the portal shows what shipped
        rather than a number someone typed beside it. */
    public const VERSION = '1.0.0';

    /** @var null|callable(Request):bool */
    private static $authorizer = null;

    /** True once the Handlers singleton has read $authorizer and kept a copy. */
    private static bool $snapshotted = false;

    /**
     * Set the authorisation check from a service provider:
     *
     *     NumraServiceProvider::authorizeUsing(fn ($r) => (bool) $r->user());
     *
     * Takes precedence over config('numra.authorize'), because a closure can
     * express what a config string cannot.
     *
     * Call it from a provider's register() or boot(), and nowhere else. Two
     * consequences of this being a static that is read once:
     *
     * 1. The Handlers singleton copies it the first time anything resolves
     *    Handlers. Setting it after that has no effect on the request in
     *    flight — it fails closed, which is the safe direction, but silently,
     *    so the call below says so in the log rather than leaving someone to
     *    wonder why their rule never runs.
     *
     * 2. The static outlives a request under a long-lived worker (Octane,
     *    Swoole, RoadRunner). A closure that captures *this* request's state
     *    would still be there for the next one, deciding it with stale data.
     *    A rule written the intended way takes the current Request as its
     *    argument and captures nothing, so the correct usage cannot leak; the
     *    warning below is what catches the other kind, because setting a
     *    request-scoped closure necessarily happens after the snapshot.
     */
    public static function authorizeUsing(?callable $check): void
    {
        if ($check !== null && self::$snapshotted) {
            /* Only for a non-null rule. Passing null is how a test or a
               teardown clears the static, and that is not a mistake. */
            logger()->warning(
                '[numra] authorizeUsing() was called after the Numra handlers were already built, '
                . 'so it does not apply to this request. Call it from a service provider instead. '
                . 'A per-request closure set here would also outlive the request under Octane.',
            );
        }

        self::$authorizer = $check;
    }

    public function register(): void
    {
        /* A new application container starts a new snapshot lifecycle. Under
           Octane register() runs once per worker, which is exactly when the
           previous snapshot stops being relevant. */
        self::$snapshotted = false;

        $this->mergeConfigFrom(__DIR__ . '/../config/numra.php', 'numra');

        $this->app->singleton(Numra::class, function ($app): Numra {
            $c = $app['config']['numra'];

            return new Numra([
                'apiKey' => (string) ($c['api_key'] ?? ''),
                'baseUrl' => $c['base_url'] ?? 'https://api.numra.ma',
                'timeout' => (float) ($c['timeout'] ?? 10),
                'maxRetries' => (int) ($c['max_retries'] ?? 2),
                'integration' => 'laravel',
            ]);
        });

        $this->app->singleton(Handlers::class, function ($app): Handlers {
            $c = $app['config']['numra'];
            self::$snapshotted = true;

            return new Handlers(
                $app->make(Numra::class),
                self::resolveAuthorizer($c['authorize'] ?? null),
                $c['webhook_secret'] ?? null,
                usage: "Numra\\Laravel\\NumraServiceProvider::authorizeUsing(fn (\$request) => (bool) \$request->user());\n"
                    . "or set NUMRA_AUTHORIZE to a Gate ability name in .env",
                log: static fn (string $m) => logger()->warning($m),
            );
        });
    }

    /**
     * There is no permissive default anywhere in this chain.
     *
     * A closure wins; then a config string, read as a Gate ability, with
     * 'auth' as shorthand for "any authenticated user"; then null, which the
     * Handlers treat as "refuse everything and say why".
     *
     * Public and static because `numra:verify` has to answer the same question
     * this does — "will the endpoint let anyone in?" — and answering it from
     * the raw config value instead gets it wrong in both directions. See
     * VerifyCommand.
     *
     * @param  mixed $ability the raw config value, normalised here so that the
     *                        provider and the command cannot disagree about it
     * @return null|callable(Request):bool
     */
    public static function resolveAuthorizer(mixed $ability): ?callable
    {
        if (self::$authorizer !== null) {
            return self::$authorizer;
        }
        /* Anything that is not a usable ability name is "no rule", the empty
           string included — that is what `NUMRA_AUTHORIZE=` in .env resolves
           to, and it shuts the endpoint exactly as an absent one does. */
        if (!\is_string($ability) || $ability === '') {
            return null;
        }
        if ($ability === 'auth') {
            /* Right for a staff dashboard, wrong for a public checkout — the
               README says so at the point someone would reach for it. */
            return static fn (Request $r): bool => $r->user() !== null;
        }

        return static function (Request $r) use ($ability): bool {
            /* Laravel returns false for an ability nobody defined, so a typo in
               NUMRA_AUTHORIZE is indistinguishable from a customer who is not
               allowed: every request denied, nothing in the log. Say it once —
               a busy checkout would otherwise write this line per request —
               and keep denying, because a name we cannot resolve is not a
               reason to let anyone through. */
            static $checked = false;
            if (!$checked) {
                $checked = true;
                if (!Gate::has($ability)) {
                    logger()->warning(
                        "[numra] NUMRA_AUTHORIZE names the Gate ability \"{$ability}\", which is not defined. "
                        . 'Laravel denies an undefined ability, so every lookup will be refused with a 403. '
                        . 'Define it with Gate::define(), or correct the name.',
                    );
                }
            }

            return Gate::forUser($r->user())->allows($ability, [$r]);
        };
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/numra.php' => $this->app->configPath('numra.php'),
            ], 'numra-config');

            $this->commands([VerifyCommand::class]);
        }

        $this->registerRouteMacro();
    }

    private function registerRouteMacro(): void
    {
        Route::macro('numra', function (?string $prefix = null, array $middleware = []) {
            $c = config('numra.route');
            $prefix ??= $c['prefix'] ?? 'api/numra';

            /* Appended, not replaced. The argument reads as "and also run
               this": `Route::numra(null, ['throttle:10,1'])` meant to tighten
               the endpoint, and replacing would instead have dropped the `api`
               group from a public route — silently, and in the direction
               nobody intended. Set route.middleware in the config to replace. */
            $stack = array_values(array_unique(array_merge($c['middleware'] ?? ['api'], $middleware)));

            /* Only on the two routes that spend quota. A 429 on the webhook
               would be a non-2xx, and Numra retries a non-2xx, so throttling
               deliveries converts a busy hour into a redelivery backlog. The
               webhook is rate-limited by Numra sending it, and its signature
               is what keeps strangers out. */
            $throttle = $c['throttle'] ?? null;
            $billable = ($throttle === null || $throttle === '') ? [] : ['throttle:' . $throttle];

            /* Explicit routes rather than a catch-all: `php artisan route:list`
               should show exactly what is reachable. A catch-all hides the
               webhook endpoint from the one command people use to audit
               what their app exposes. */
            return Route::prefix($prefix)
                ->middleware($stack)
                ->group(static function () use ($billable): void {
                    Route::post('check', [NumraController::class, 'check'])
                        ->name('numra.check')->middleware($billable);
                    Route::post('outcome', [NumraController::class, 'outcome'])
                        ->name('numra.outcome')->middleware($billable);
                    Route::post('webhook', [NumraController::class, 'webhook'])
                        ->name('numra.webhook')
                        /* Numra is not a browser and has no session, so a CSRF
                           token it cannot have would reject every delivery.
                           The signature is the authentication here. */
                        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
                });
        });
    }
}
