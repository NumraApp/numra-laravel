<?php

declare(strict_types=1);

namespace Numra\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Numra\Laravel\Events\NumraWebhookReceived;

final class RouteTest extends TestCase
{
    private const EVT = '{"id":"evt_1","event":"verification.flagged","data":{"phone":"+212600000000"}}';

    public function test_with_no_authorize_rule_every_request_is_refused(): void
    {
        $this->boot([]);

        $res = $this->postJson('api/numra/check', ['phone' => '0600000000']);

        /* 500 and not 403: nobody configured this to be locked, it just is.
           A 403 would read as "this user lacks permission" and send the
           developer hunting through their Gate definitions. */
        $res->assertStatus(500)->assertJsonPath('error', 'NUMRA_NOT_CONFIGURED');
        self::assertCount(0, $this->transport->calls, 'no quota spent');
    }

    public function test_a_rejecting_rule_is_a_plain_403(): void
    {
        $this->boot([], static fn (): bool => false);

        $this->postJson('api/numra/check', ['phone' => '0600000000'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');
        self::assertCount(0, $this->transport->calls);
    }

    public function test_a_rule_that_throws_fails_closed(): void
    {
        /* A Gate that hits a dead database must not become an open door. */
        $this->boot([], static function (): bool {
            throw new \RuntimeException('db down');
        });

        $this->postJson('api/numra/check', ['phone' => '0600000000'])->assertStatus(403);
        self::assertCount(0, $this->transport->calls);
    }

    public function test_check_returns_the_narrowed_payload_not_the_engine_internals(): void
    {
        $this->boot([['body' => self::LOOKUP_OK]], static fn (): bool => true);

        $res = $this->postJson('api/numra/check', ['phone' => '0600000000']);

        $res->assertOk()
            ->assertJsonPath('riskLevel', 'HIGH')
            ->assertJsonPath('customerStyle.code', 'reactive')
            /* `raw` would leak the shape of our ledger; risk_score_raw is
               engine diagnostics. Neither is the browser's business. */
            ->assertJsonMissingPath('raw')
            ->assertJsonMissingPath('risk_score_raw');

        self::assertSame('https://api.example.test/v1/phone/lookup', $this->transport->calls[0]['url']);
    }

    public function test_a_phone_in_the_query_string_never_reaches_the_wire(): void
    {
        /* $request->all() merges the query string into the parsed body, so
           this request used to succeed and put the customer's number on the
           wire — and, on the way, into the merchant's access log, into every
           proxy in front of it, and into the Referer of whatever the page
           loaded next. The body is the only input now. */
        $this->boot([], static fn (): bool => true);

        $this->json('POST', 'api/numra/check?phone=0600000000', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'INVALID_PAYLOAD');

        self::assertCount(0, $this->transport->calls, 'the query string must not reach the transport');
    }

    public function test_outcome_ignores_the_query_string_too(): void
    {
        $this->boot([], static fn (): bool => true);

        $this->json('POST', 'api/numra/outcome?phone=0600000000&orderId=A1&outcomeType=REFUSED_COD', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'INVALID_PAYLOAD');

        self::assertCount(0, $this->transport->calls);
    }

    public function test_a_form_encoded_body_is_still_accepted(): void
    {
        /* Reading only JSON would have closed the same leak and broken every
           caller posting a form, for whom nothing was leaking: a form body is
           not written to an access log the way a query string is. */
        $this->boot([['body' => self::LOOKUP_OK]], static fn (): bool => true);

        $this->call('POST', 'api/numra/check', ['phone' => '0600000000'], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ])->assertOk()->assertJsonPath('riskLevel', 'HIGH');

        self::assertCount(1, $this->transport->calls);
    }

    public function test_a_missing_phone_is_a_400_before_anything_is_spent(): void
    {
        $this->boot([], static fn (): bool => true);

        $this->postJson('api/numra/check', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'INVALID_PAYLOAD');
        self::assertCount(0, $this->transport->calls);
    }

    public function test_a_rejected_credential_never_reaches_the_browser_as_401(): void
    {
        /* A 401 in a browser reads as "you are logged out", which is a lie —
           the merchant's credential is the problem, not the visitor's session. */
        $this->boot([[
            'status' => 401,
            'body' => ['ok' => false, 'error' => 'LICENSE_EXPIRED', 'message' => 'expired'],
        ]], static fn (): bool => true);

        $this->postJson('api/numra/check', ['phone' => '0600000000'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'UPSTREAM_UNAVAILABLE');
    }

    public function test_a_signed_webhook_verifies_and_dispatches_the_event(): void
    {
        Event::fake([NumraWebhookReceived::class]);
        $this->boot([], static fn (): bool => true);

        $this->call('POST', 'api/numra/webhook', [], [], [], $this->serverHeaders(self::sign(self::EVT)), self::EVT)
            ->assertOk();

        Event::assertDispatched(NumraWebhookReceived::class, static function (NumraWebhookReceived $e): bool {
            return $e->id() === 'evt_1' && $e->name() === 'verification.flagged';
        });
    }

    public function test_a_forged_signature_is_400_and_dispatches_nothing(): void
    {
        Event::fake([NumraWebhookReceived::class]);
        $this->boot([], static fn (): bool => true);

        $this->call('POST', 'api/numra/webhook', [], [], [], $this->serverHeaders([
            'Numra-Signature' => 'sha256=deadbeef',
            'Numra-Timestamp' => (string) time(),
        ]), self::EVT)->assertStatus(400);

        /* 400 not 401: an unauthentic sender has no credential to fix, and a
           401 invites a retry storm. */
        Event::assertNotDispatched(NumraWebhookReceived::class);
    }

    public function test_a_stale_timestamp_is_rejected_as_a_replay(): void
    {
        $this->boot([], static fn (): bool => true);

        $this->call('POST', 'api/numra/webhook', [], [], [], $this->serverHeaders(self::sign(self::EVT, time() - 3600)), self::EVT)
            ->assertStatus(400)
            ->assertJsonPath('error', 'expired');
    }

    /**
     * @param  array<string, string> $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $server;
    }
}
