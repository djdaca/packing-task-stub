<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function count;
use function is_array;
use function is_string;
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
            $this->cache->storeSelectedBox($products, $box->getId());
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
                'item_coordinates' => true,
                'images_sbs' => false,
                'images_separated' => false,
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
            '[PackingAPI] API returned status %d (%s)%s',
            $statusCode,
            $this->getHttpStatusName($statusCode),
            $isRetriable ? ' - Will use fallback' : ''
        );

        if ($isRetriable) {
            $this->logger->warning($logMessage);

            throw new ThirdPartyPackingException(
                sprintf('Third-party API unavailable (status %d). Falling back to local calculation.', $statusCode)
            );
        }

        $this->logger->error($logMessage);

        throw new ThirdPartyPackingException(
            sprintf('Third-party packing API error: status %d.', $statusCode)
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
        if (isset($payload['unfitted_items']) && is_array($payload['unfitted_items'])) {
            return $payload['unfitted_items'] === [];
        }

        $responseNode = $payload['response'] ?? null;
        if (is_array($responseNode)) {
            $responseUnfittedItems = $responseNode['unfitted_items'] ?? null;
            if (is_array($responseUnfittedItems)) {
                return $responseUnfittedItems === [];
            }
        }

        $rootBinsPacked = $payload['bins_packed'] ?? null;
        if (is_array($rootBinsPacked) && isset($rootBinsPacked[0]) && is_array($rootBinsPacked[0])) {
            $rootPackedItems = $rootBinsPacked[0]['items'] ?? null;
            if (is_array($rootPackedItems)) {
                return count($rootPackedItems) === $requestedItemCount;
            }
        }

        if (is_array($responseNode)) {
            $responseBinsPacked = $responseNode['bins_packed'] ?? null;
            if (is_array($responseBinsPacked) && isset($responseBinsPacked[0]) && is_array($responseBinsPacked[0])) {
                $responsePackedItems = $responseBinsPacked[0]['items'] ?? null;
                if (is_array($responsePackedItems)) {
                    return count($responsePackedItems) === $requestedItemCount;
                }
            }
        }

        throw new ThirdPartyPackingException('Third-party packing API response did not contain packability info.');
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

        foreach ($payload as $key => $_) {
            if (!is_string($key)) {
                throw new ThirdPartyPackingException('Third-party packing API returned invalid payload shape.');
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
