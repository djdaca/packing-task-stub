<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function count;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

final class ThirdPartyPackabilityCheckerAdapter implements PackabilityCheckerPort
{
    private const array RETRIABLE_STATUS_CODES = [408, 429, 503, 504]; // Timeout, RateLimit, ServiceUnavailable, GatewayTimeout

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiUsername,
        private string $apiKey,
        private PackingCachePort $cache,
    ) {
    }

    /**
     * @param list<Product> $products
     */
    public function canPackIntoBox(array $products, Box $box): bool
    {
        $this->assertConfigured();

        $payload = $this->buildPayload($products, $box);
        $response = $this->sendRequest($payload);
        $this->assertResponseStatus($response);

        $decoded = $this->decodeResponseBody($response);
        $typedDecoded = $this->normalizePayload($decoded);
        $canPack = $this->extractPackable($typedDecoded, count($products));

        // Cache only successful API responses (not fallback results)
        if ($canPack) {
            $boxId = $box->getId();
            if ($boxId === null) {
                $this->logger->warning('[PackingAPI] Selected box has no ID; skipping cache write.');

                return $canPack;
            }

            $this->cache->storeSelectedBox($products, $boxId);
        }

        return $canPack;
    }

    private function assertConfigured(): void
    {
        if ($this->apiUrl === '' || $this->apiUsername === '' || $this->apiKey === '') {
            $this->logger->error('[PackingAPI] Missing third-party packing API configuration.');

            throw new ThirdPartyPackingException('Missing third-party packing API configuration.');
        }
    }

    /**
     * @param list<Product> $products
     * @return array<string, mixed>
     */
    private function buildPayload(array $products, Box $box): array
    {
        $items = [];
        foreach ($products as $index => $product) {
            $items[] = [
                'id' => sprintf('item-%d', $index + 1),
                'w' => $product->getWidth(),
                'h' => $product->getHeight(),
                'd' => $product->getLength(),
                'wg' => $product->getWeight(),
                'q' => 1,
            ];
        }

        return [
            'username' => $this->apiUsername,
            'api_key' => $this->apiKey,
            'bins' => [[
                'id' => sprintf('box-%d', $box->getId() ?? 0),
                'w' => $box->getWidth(),
                'h' => $box->getHeight(),
                'd' => $box->getLength(),
                'max_wg' => $box->getMaxWeight(),
            ]],
            'items' => $items,
            'params' => [
                'optimization_mode' => 'bins_number',
                'item_distribution' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendRequest(array $payload): ResponseInterface
    {
        $this->logger->info('[PackingAPI] Sending request to external API', ['url' => $this->apiUrl]);
        $this->logger->debug('[PackingAPI] Payload', ['payload' => $payload]);

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $request = $this->requestFactory->createRequest('POST', $this->apiUrl)
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
            $response = $this->httpClient->sendRequest($request);
            $this->logger->info('[PackingAPI] Response status', ['status' => $response->getStatusCode()]);
            $this->logger->debug('[PackingAPI] Response body', ['body' => (string) $response->getBody()]);

            return $response;
        } catch (\JsonException $exception) {
            $this->logger->error('[PackingAPI] Payload serialization failed', ['error' => $exception->getMessage()]);

            throw new ThirdPartyPackingException('Third-party packing API payload serialization failed.', 0, $exception);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('[PackingAPI] Network failure', ['error' => $exception->getMessage()]);

            throw new ThirdPartyPackingException('Third-party packing API network failure.', 0, $exception);
        }
    }

    private function assertResponseStatus(ResponseInterface $response): void
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $isRetriable = in_array($statusCode, self::RETRIABLE_STATUS_CODES, true);

        $logMessage = sprintf(
            '[PackingAPI] API returned status %d (%s) - Will use fallback',
            $statusCode,
            $this->getHttpStatusName($statusCode),
        );

        if ($isRetriable) {
            $this->logger->warning($logMessage);
        } else {
            $this->logger->error($logMessage);
        }

        throw new ThirdPartyPackingException(
            sprintf('Third-party API error (status %d). Falling back to local calculation.', $statusCode)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponseBody(ResponseInterface $response): array
    {
        $rawBody = (string) $response->getBody();
        if ($rawBody === '') {
            throw new ThirdPartyPackingException('Third-party packing API returned empty response.');
        }

        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ThirdPartyPackingException('Third-party packing API returned invalid JSON.', 0, $exception);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractPackable(array $payload, int $requestedItemCount): bool
    {
        // API response structure: { "response": { "bins_packed": [ { "items": [...] } ] } }
        $responseNode = $payload['response'] ?? null;
        if (!is_array($responseNode)) {
            $this->logger->error('[PackingAPI] Response does not contain "response" node.');

            return false;
        }

        $binsPacked = $responseNode['bins_packed'] ?? null;
        if (!is_array($binsPacked) || !isset($binsPacked[0])) {
            $this->logger->error('[PackingAPI] Response does not contain "bins_packed" array.');

            return false;
        }

        $firstBin = $binsPacked[0];
        if (!is_array($firstBin)) {
            $this->logger->error('[PackingAPI] First bin is not an array.');

            return false;
        }

        $items = $firstBin['items'] ?? null;
        if (!is_array($items)) {
            $this->logger->error('[PackingAPI] Bin does not contain "items" array.');

            return false;
        }

        // Check if all requested items were packed
        $packedCount = count($items);
        $canPack = $packedCount === $requestedItemCount;

        $this->logger->debug('[PackingAPI] Packability result', [
            'requestedItemCount' => $requestedItemCount,
            'packedCount' => $packedCount,
            'canPack' => $canPack,
        ]);

        return $canPack;
    }

    private function getHttpStatusName(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            408 => 'Request Timeout',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'HTTP Error',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new ThirdPartyPackingException('Third-party packing API returned invalid payload shape.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
