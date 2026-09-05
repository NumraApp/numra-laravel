<?php

declare(strict_types=1);

namespace Numra\Laravel\Tests;

use Numra\Transport;

/** A scripted transport, so no test reaches the network. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, body: string}> */
    public array $calls = [];

    /** @param list<array{status?: int, headers?: array<string,string>, body?: mixed}> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body];
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \LogicException('FakeTransport ran out of scripted responses.');
        }

        return [
            'status' => $next['status'] ?? 200,
            'headers' => $next['headers'] ?? [],
            'body' => json_encode($next['body'] ?? [], JSON_THROW_ON_ERROR),
        ];
    }
}
