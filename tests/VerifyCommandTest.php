<?php

declare(strict_types=1);

namespace Numra\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Numra\Laravel\NumraServiceProvider;
use Numra\Numra;

final class VerifyCommandTest extends TestCase
{
    private function fake(array $responses): void
    {
        $this->transport = new FakeTransport($responses);
        $this->app->forgetInstance(Numra::class);
        $this->app->singleton(Numra::class, fn (): Numra => new Numra([
            'apiKey' => 'numra_test_key',
            'baseUrl' => 'https://api.example.test',
            'transport' => $this->transport,
        ]));
    }

    public function test_an_unlimited_plan_is_never_reported_as_zero_left(): void
    {
        /* daily_limit: null means unlimited. Printing "0" here would be the
           exact opposite of the truth, and this command is what someone runs
           at deploy time to decide whether they are fine. */
        $this->fake([['body' => [
            'ok' => true, 'license_status' => 'ACTIVE', 'plan' => 'scale',
            'daily_limit' => null, 'daily_used' => 412, 'unlimited' => true,
        ]]]);

        $this->artisan('numra:verify')
            ->expectsOutputToContain('412 of unlimited')
            ->assertSuccessful();
    }

    public function test_it_warns_that_the_endpoint_is_refusing_everything(): void
    {
        /* config('numra.authorize') is null in these tests. Without this
           warning a deploy looks healthy right up until the first lookup. */
        $this->fake([['body' => [
            'ok' => true, 'license_status' => 'ACTIVE', 'daily_limit' => 5000, 'daily_used' => 12,
        ]]]);
        Route::numra();

        $this->artisan('numra:verify')
            ->expectsOutputToContain('refuses every request')
            ->assertSuccessful();
    }

    public function test_an_empty_authorize_value_is_reported_as_no_rule(): void
    {
        /* NUMRA_AUTHORIZE= in .env — a set-but-empty variable, which is the
           most common way to get this wrong. It resolves to no rule, so the
           endpoint refuses everything, but the raw config value is '' rather
           than null and the old check waved it through. */
        config()->set('numra.authorize', '');
        $this->fake([['body' => [
            'ok' => true, 'license_status' => 'ACTIVE', 'daily_limit' => 5000, 'daily_used' => 12,
        ]]]);
        Route::numra();

        $this->artisan('numra:verify')
            ->expectsOutputToContain('refuses every request')
            ->assertSuccessful();
    }

    public function test_a_closure_rule_is_not_reported_as_missing(): void
    {
        /* authorizeUsing() is the form the README recommends first, and it
           never touches config. Warning here told a correctly configured app
           that its endpoint was shut, which is how a real warning gets
           learned as noise. */
        NumraServiceProvider::authorizeUsing(static fn (): bool => true);
        $this->fake([['body' => [
            'ok' => true, 'license_status' => 'ACTIVE', 'daily_limit' => 5000, 'daily_used' => 12,
        ]]]);
        Route::numra();

        $this->artisan('numra:verify')
            ->doesntExpectOutputToContain('refuses every request')
            ->assertSuccessful();
    }

    public function test_it_stays_quiet_when_no_endpoint_is_mounted(): void
    {
        /* Route::numra() was never called, so there is no endpoint to refuse
           anything and nothing to warn about. The clause this replaced tested
           whether the Handlers singleton was bound, which register() does
           unconditionally — always true, in every app. */
        $this->fake([['body' => [
            'ok' => true, 'license_status' => 'ACTIVE', 'daily_limit' => 5000, 'daily_used' => 12,
        ]]]);

        $this->artisan('numra:verify')
            ->doesntExpectOutputToContain('refuses every request')
            ->assertSuccessful();
    }

    public function test_a_rejected_credential_fails_the_command(): void
    {
        /* Non-zero exit, so a deploy script can stop instead of shipping a
           checkout that will not finish. */
        $this->fake([[
            'status' => 401,
            'body' => ['ok' => false, 'error' => 'LICENSE_EXPIRED', 'message' => 'expired'],
        ]]);

        $this->artisan('numra:verify')->assertFailed();
    }
}
