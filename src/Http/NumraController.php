<?php

declare(strict_types=1);

namespace Numra\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Numra\Handlers;
use Numra\Laravel\Events\NumraWebhookReceived;

/**
 * Three routes, and no decisions of its own.
 *
 * Everything that could differ between frameworks — deny by default, the
 * browser-facing subset, error translation — lives in Numra\Handlers, which is
 * the PHP twin of createHandlers in @numra/core. This controller turns a
 * Request into an array and an array into a JsonResponse, and that is all.
 */
final class NumraController
{
    public function __construct(private readonly Handlers $handlers)
    {
    }

    public function check(Request $request): JsonResponse
    {
        return $this->respond($this->handlers->check(self::body($request), $request));
    }

    public function outcome(Request $request): JsonResponse
    {
        return $this->respond($this->handlers->outcome(self::body($request), $request));
    }

    /**
     * The body, and only the body.
     *
     * Not `$request->all()`: that is the parsed body *plus* the query string,
     * so `POST /api/numra/check?phone=0600000000` with an empty body would
     * reach the wire. A phone number in a URL is a phone number in the access
     * log, in every proxy in front of it, and in the `Referer` of whatever the
     * page loads next — which is the class of leak this package refuses to run
     * in a browser to avoid. Every JS adapter reads the parsed body alone; so
     * does this.
     *
     * Form-encoded bodies are still accepted, unlike the JS adapters, which
     * take JSON only. A form body is not written to an access log the way a
     * query string is, so refusing it would break existing callers without
     * closing anything. `$request->json()->all()` alone would do exactly that:
     * it returns empty for `application/x-www-form-urlencoded`, and the
     * symptom would be a 400 on a request that used to work.
     *
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->post();
    }

    public function webhook(Request $request): JsonResponse
    {
        /* getContent() returns the exact bytes. Laravel parses JSON lazily
           into `input()` and never consumes the stream, so unlike Express
           there is nothing to configure — but it does mean nothing else may
           call getContent(false) and replace it first. */
        $out = $this->handlers->webhook($request->getContent(), $request->headers->all());

        if (isset($out['event'])) {
            /* An event, not a callback. Numra retries on a non-2xx, so a
               listener that runs inline and takes too long turns one delivery
               into several — make yours ShouldQueue and the response goes out
               before the work starts. The README says this in bold. */
            event(new NumraWebhookReceived($out['event']));
        }

        return $this->respond($out);
    }

    /** @param array{status: int, body: array<string, mixed>} $out */
    private function respond(array $out): JsonResponse
    {
        return response()->json($out['body'], $out['status']);
    }
}
