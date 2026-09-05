<?php

declare(strict_types=1);

namespace Numra\Laravel\Tests;

use Illuminate\Support\Facades\Route;

final class OptInTest extends TestCase
{
    public function test_installing_the_package_mounts_nothing(): void
    {
        /* The provider is registered — see getPackageProviders — and still
           there is no endpoint. An endpoint that exists is an endpoint
           somebody can call, so mounting one has to be a decision the
           application makes out loud. */
        $this->postJson('api/numra/check', ['phone' => '0600000000'])->assertNotFound();
    }

    public function test_the_macro_mounts_exactly_three_named_routes(): void
    {
        Route::numra();

        $mounted = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($r): bool => str_starts_with((string) $r->getName(), 'numra.'))
            ->map(static fn ($r): string => $r->methods()[0] . ' ' . $r->uri())
            ->values()
            ->all();

        /* Named and explicit rather than a catch-all, so `php artisan
           route:list` shows the webhook endpoint. That command is how people
           audit what their app exposes; a catch-all hides it. */
        self::assertSame([
            'POST api/numra/check',
            'POST api/numra/outcome',
            'POST api/numra/webhook',
        ], $mounted);
    }

    public function test_extra_middleware_is_appended_rather_than_substituted(): void
    {
        Route::numra(null, ['throttle:10,1']);

        /* By URI, not by name: `->name()` is applied after the route is added
           to the collection, so RouteCollection's name lookup is stale until a
           request refreshes it, and getByName() returns null in a test that
           never dispatches. */
        $check = self::routeFor('api/numra/check')->gatherMiddleware();

        /* Someone passing this is tightening the endpoint. Replacing would
           have answered by dropping the `api` group from a public route. */
        self::assertContains('api', $check);
        self::assertContains('throttle:10,1', $check);
    }

    public function test_the_billable_routes_are_throttled_and_the_webhook_is_not(): void
    {
        Route::numra();

        $check = self::routeFor('api/numra/check')->gatherMiddleware();
        $outcome = self::routeFor('api/numra/outcome')->gatherMiddleware();
        $webhook = self::routeFor('api/numra/webhook')->gatherMiddleware();

        /* The `api` group carried throttle:api on Laravel 10 and is empty on
           11 and 12 unless the app calls ->throttleApi(), so a stock Laravel
           12 store had no limit on an endpoint billed to the merchant. */
        self::assertContains('throttle:60,1', $check);
        self::assertContains('throttle:60,1', $outcome);

        /* Not the webhook. A 429 is a non-2xx, Numra retries a non-2xx, and
           the delivery would come straight back. */
        self::assertNotContains('throttle:60,1', $webhook);
    }

    public function test_the_prefix_can_be_moved(): void
    {
        Route::numra('internal/risk');

        $this->postJson('internal/risk/check', ['phone' => '0600000000'])
            ->assertStatus(500)
            ->assertJsonPath('error', 'NUMRA_NOT_CONFIGURED');
    }

    private static function routeFor(string $uri): \Illuminate\Routing\Route
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        throw new \RuntimeException("no route mounted at {$uri}");
    }
}
