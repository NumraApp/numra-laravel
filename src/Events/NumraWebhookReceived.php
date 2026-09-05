<?php

declare(strict_types=1);

namespace Numra\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A webhook that passed signature and replay checks.
 *
 * Dispatched only after verification, so a listener never has to ask whether
 * the payload is authentic.
 *
 * It does still have to ask whether it has seen this delivery before: Numra
 * retries on a non-2xx, and a retry carries the same envelope `id`. Key your
 * idempotency on `$event->id`.
 */
final class NumraWebhookReceived
{
    use Dispatchable;

    /** @param array<string, mixed> $payload the verified envelope */
    public function __construct(public readonly array $payload)
    {
    }

    /** The envelope id. De-duplicate on this. */
    public function id(): ?string
    {
        return isset($this->payload['id']) ? (string) $this->payload['id'] : null;
    }

    /** e.g. verification.flagged */
    public function name(): ?string
    {
        return isset($this->payload['event']) ? (string) $this->payload['event'] : null;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return \is_array($this->payload['data'] ?? null) ? $this->payload['data'] : [];
    }
}
