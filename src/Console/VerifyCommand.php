<?php

declare(strict_types=1);

namespace Numra\Laravel\Console;

use Illuminate\Console\Command;
use Numra\Laravel\Http\NumraController;
use Numra\Laravel\NumraServiceProvider;
use Numra\Numra;
use Numra\NumraError;

/**
 * `php artisan numra:verify`
 *
 * A deploy-time check. The failure this exists to catch is a credential that
 * is wrong or expired: without it the first person to find out is a customer
 * at checkout, and the symptom they see is a page that will not finish.
 */
final class VerifyCommand extends Command
{
    protected $signature = 'numra:verify';

    protected $description = 'Check the Numra credential and show the quota left today.';

    public function handle(Numra $numra): int
    {
        try {
            $l = $numra->verifyLicense();
        } catch (NumraError $e) {
            $this->components->error("Numra rejected this credential: {$e->errorCode}");
            $this->line('  ' . $e->getMessage());
            if ($e->isAuthError()) {
                $this->line('  Check NUMRA_API_KEY. Retrying will not help.');
            }

            return self::FAILURE;
        }

        $this->components->info("Credential accepted — {$l->status}" . ($l->plan ? " ({$l->plan})" : ''));

        /* null is unlimited. Printing "0 left" here would be the exact
           opposite of the truth, which is why the client never coerces it. */
        $this->line($l->dailyLimit === null
            ? "  Lookups today: {$l->dailyUsed} of unlimited"
            : "  Lookups today: {$l->dailyUsed} of {$l->dailyLimit}");

        if ($l->expiresAt !== null) {
            $this->line("  Expires: {$l->expiresAt}");
        }

        $this->warnIfTheEndpointIsMountedAndShut();

        return self::SUCCESS;
    }

    /**
     * The endpoint is only refused at request time, which means a deploy can
     * look healthy right up until the first lookup. Say it now instead.
     */
    private function warnIfTheEndpointIsMountedAndShut(): void
    {
        /* Whether a route is mounted, not whether the Handlers singleton is
           bound. The singleton is registered unconditionally in register(), so
           the old test was true in every app and this warning fired at people
           who had never called Route::numra() — for whom there is no endpoint
           to refuse anything.

           Found by controller rather than by route name. `->name()` is applied
           after the route is added to the collection, so RouteCollection's
           name lookup is stale until something refreshes it — which a request
           does and `artisan` does not. Looking the name up here silently
           returned null and the warning never fired, which is the opposite of
           the bug it was written to fix. Scanning the collection also catches
           a merchant who mounted the controller by hand rather than through
           the macro. */
        if (!$this->numraRouteIsMounted()) {
            return;
        }

        /* The resolved rule, not config('numra.authorize'). That value gets it
           wrong in both directions: NUMRA_AUTHORIZE= in .env resolves to null
           and shuts the endpoint, but reads here as '' rather than null, so the
           one command written to catch this deploy failure missed its most
           common spelling; and authorizeUsing() — the form the README
           recommends first — never touches config, so a correctly configured
           app was told it was broken. */
        if (NumraServiceProvider::resolveAuthorizer(config('numra.authorize')) !== null) {
            return;
        }

        $this->newLine();
        $this->components->warn('No authorize rule is set, so the /check endpoint refuses every request.');
        $this->line('  Set NUMRA_AUTHORIZE, or call NumraServiceProvider::authorizeUsing() in a provider.');
    }

    private function numraRouteIsMounted(): bool
    {
        foreach ($this->laravel['router']->getRoutes() as $route) {
            if (str_contains((string) $route->getActionName(), NumraController::class)) {
                return true;
            }
        }

        return false;
    }
}
