<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
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
use function strtolower;

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
                'item_distribution' => false,
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
        $responseNode = $this->extractResponseNode($payload);
        if ($responseNode === null) {
            return false;
        }

        $this->assertApiAccessAvailable($responseNode);

        $status = $responseNode['status'] ?? null;
        $binsPacked = $this->extractBinsPacked($responseNode);
        if ($binsPacked === null) {
            return false;
        }

        if (!$this->hasSinglePackedBin($binsPacked)) {
            return false;
        }

        if (!$this->hasNoNotPackedItems($responseNode)) {
            return false;
        }

        if ($status !== 1) {
            $this->logger->warning('[PackingAPI] Response status indicates failure.', ['status' => $status]);

            return false;
        }

        $items = $this->extractPackedItemsFromFirstBin($binsPacked);
        if ($items === null) {
            return false;
        }

        $packedCount = $this->countUniquePackedItemIds($items);
        if ($packedCount === null) {
            return false;
        }

        $canPack = $packedCount === $requestedItemCount;

        $this->logger->debug('[PackingAPI] Packability result', [
            'requestedItemCount' => $requestedItemCount,
            'packedCount' => $packedCount,
            'canPack' => $canPack,
        ]);

        return $canPack;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function extractResponseNode(array $payload): array|null
    {
        $responseNode = $payload['response'] ?? null;
        if (!is_array($responseNode)) {
            $this->logger->error('[PackingAPI] Response does not contain "response" node.');

            return null;
        }

        /** @var array<string, mixed> $responseNode */
        return $responseNode;
    }

    /**
     * @param array<string, mixed> $responseNode
     */
    private function assertApiAccessAvailable(array $responseNode): void
    {
        $status = $responseNode['status'] ?? null;
        if (is_int($status) && $status < 0) {
            $this->logger->error('[PackingAPI] API returned negative status, using fallback.', ['status' => $status]);

            throw new ThirdPartyPackingException(
                sprintf('Third-party API application error (status %d). Falling back to local calculation.', $status)
            );
        }

        $errors = $responseNode['errors'] ?? null;
        if (!is_array($errors)) {
            return;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $message = $error['message'] ?? null;
            if (!is_string($message)) {
                continue;
            }

            $normalizedMessage = strtolower($message);
            if (
                str_contains($normalizedMessage, 'locked out')
                || str_contains($normalizedMessage, 'banned')
            ) {
                $this->logger->error('[PackingAPI] API access is blocked, using fallback.', [
                    'message' => $message,
                ]);

                throw new ThirdPartyPackingException('Third-party API access blocked. Falling back to local calculation.');
            }
        }
    }

    /**
     * @param array<string, mixed> $responseNode
     * @return list<mixed>|null
     */
    private function extractBinsPacked(array $responseNode): array|null
    {
        $binsPacked = $responseNode['bins_packed'] ?? null;
        if (!is_array($binsPacked) || !isset($binsPacked[0])) {
            $this->logger->error('[PackingAPI] Response does not contain "bins_packed" array.');

            return null;
        }

        return array_values($binsPacked);
    }

    /**
     * @param list<mixed> $binsPacked
     */
    private function hasSinglePackedBin(array $binsPacked): bool
    {
        $binsPackedCount = count($binsPacked);
        if ($binsPackedCount !== 1) {
            $this->logger->debug('[PackingAPI] Items were distributed to multiple bins.', [
                'binsPackedCount' => $binsPackedCount,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $responseNode
     */
    private function hasNoNotPackedItems(array $responseNode): bool
    {
        $notPackedItems = $responseNode['not_packed_items'] ?? null;
        if (!is_array($notPackedItems)) {
            $this->logger->error('[PackingAPI] Response does not contain "not_packed_items" array.');

            return false;
        }

        if (count($notPackedItems) > 0) {
            $this->logger->debug('[PackingAPI] Response contains not packed items.', [
                'notPackedCount' => count($notPackedItems),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param list<mixed> $binsPacked
     * @return list<mixed>|null
     */
    private function extractPackedItemsFromFirstBin(array $binsPacked): array|null
    {
        $firstBin = $binsPacked[0];
        if (!is_array($firstBin)) {
            $this->logger->error('[PackingAPI] First bin is not an array.');

            return null;
        }

        $items = $firstBin['items'] ?? null;
        if (!is_array($items)) {
            $this->logger->error('[PackingAPI] Bin does not contain "items" array.');

            return null;
        }

        return array_values($items);
    }

    /**
     * @param list<mixed> $items
     */
    private function countUniquePackedItemIds(array $items): int|null
    {
        $packedItemIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $this->logger->error('[PackingAPI] Packed item entry is not an array.');

                return null;
            }

            $itemId = $item['id'] ?? null;
            if (!is_string($itemId) || $itemId === '') {
                $this->logger->error('[PackingAPI] Packed item is missing a valid "id".');

                return null;
            }

            $packedItemIds[] = $itemId;
        }

        return count(array_unique($packedItemIds));
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
