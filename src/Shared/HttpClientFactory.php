<?php

declare(strict_types=1);

namespace App\Shared;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class HttpClientFactory
{
    public function __construct(
        private int $timeoutSeconds,
        private bool $httpErrors = false
    ) {
    }

    public function createClient(): ClientInterface
    {
        return new Client([
            'timeout' => $this->timeoutSeconds,
            'http_errors' => $this->httpErrors,
        ]);
    }

    public function createRequestFactory(): RequestFactoryInterface
    {
        return new HttpFactory();
    }

    public function createStreamFactory(): StreamFactoryInterface
    {
        return new HttpFactory();
    }
}
