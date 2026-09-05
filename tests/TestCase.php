<?php

declare(strict_types=1);

namespace Numra\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Numra\Laravel\NumraServiceProvider;
use Numra\Numra;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const SECRET = 'whsec_test';

    protected const LOOKUP_OK = [
        'ok' => true,
        'phone' => '+212600000000',
        'carrier' => ['code' => 'IAM', 'label' => 'Maroc Telecom'],
        'verdict' => 'RATED',
        'verdict_source' => 'events',
        'risk_score' => 72,
        'risk_score_raw' => 68.4,
        'risk_level' => 'HIGH',
        'trust_score' => 28,
        'confidence' => 61,
        'is_rated' => true,
        'total_events' => 9,
        'customer_style' => [
            'code' => 'reactive', 'label' => 'Reactive', 'icon' => '⚡',
            'color' => '#F26D6D', 'risk_sensitivity' => 1.2,
        ],
        'is_blacklisted' => false,
        'cache_ttl_seconds' => 3600,
    ];

    protected FakeTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [NumraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('numra.api_key', 'numra_test_key');
        $app['config']->set('numra.base_url', 'https://api.example.test');
        $app['config']->set('numra.webhook_secret', self::SECRET);
        /* Left null on purpose. Every test that wants the endpoint open has
           to say so, which is the same demand the package makes of a real
           application. */
        $app['config']->set('numra.authorize', null);
    }

    protected function setUp(): void
    {
        /* A fresh authorizer per test — it is static, so one test leaking it
           into the next would make the deny-by-default test pass for the
           wrong reason. */
        NumraServiceProvider::authorizeUsing(null);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        NumraServiceProvider::authorizeUsing(null);
        parent::tearDown();
    }

    /** Mount the routes and swap in a scripted transport. */
    protected function boot(array $responses = [], ?callable $authorize = null): void
    {
        if ($authorize !== null) {
            NumraServiceProvider::authorizeUsing($authorize);
        }

        $this->transport = new FakeTransport($responses);
        $this->app->forgetInstance(Numra::class);
        $this->app->forgetInstance(\Numra\Handlers::class);
        $this->app->singleton(Numra::class, fn (): Numra => new Numra([
            'apiKey' => 'numra_test_key',
            'baseUrl' => 'https://api.example.test',
            'transport' => $this->transport,
        ]));

        Route::numra();
    }

    /** @return array<string, string> */
    protected static function sign(string $body, ?int $ts = null): array
    {
        $ts ??= time();

        return [
            'Numra-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET),
            'Numra-Timestamp' => (string) $ts,
        ];
    }
}
