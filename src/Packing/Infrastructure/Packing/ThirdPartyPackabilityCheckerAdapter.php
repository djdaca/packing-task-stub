<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function array_key_exists;
use function array_slice;
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
     * @param list<Box> $boxes
     */
    public function findFirstPackableBox(array $products, array $boxes): Box|null
    {
        if ($boxes === []) {
            return null;
        }

        $this->assertConfigured();

        $selectedBox = $this->findFirstPackableBoxInOrder($products, $boxes);
        if ($selectedBox === null) {
            return null;
        }

        $boxId = $selectedBox->getId();
        if ($boxId === null) {
            $this->logger->warning('[PackingAPI] Selected box has no ID; skipping cache write.');

            return $selectedBox;
        }

        $this->cache->storeSelectedBox($products, $boxId);

        return $selectedBox;
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    private function findFirstPackableBoxInOrder(array $products, array $boxes, bool $segmentIsKnownPackable = false): Box|null
    {
        if ($boxes === []) {
            return null;
        }

        if (!$segmentIsKnownPackable) {
            $anyPackable = $this->findAnyPackableBox($products, $boxes);
            if ($anyPackable === null) {
                return null;
            }
        }

        if (count($boxes) === 1) {
            return $boxes[0];
        }

        $middle = intdiv(count($boxes), 2);
        $leftBoxes = array_slice($boxes, 0, $middle);
        $rightBoxes = array_slice($boxes, $middle);

        $leftHasPackable = $this->findAnyPackableBox($products, $leftBoxes) !== null;
        if ($leftHasPackable) {
            return $this->findFirstPackableBoxInOrder($products, $leftBoxes, true);
        }

        return $this->findFirstPackableBoxInOrder($products, $rightBoxes, true);
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    private function findAnyPackableBox(array $products, array $boxes): Box|null
    {
        [$payload, $boxByExternalId] = $this->buildPayload($products, $boxes);
        $response = $this->sendRequest($payload);
        $this->assertResponseStatus($response);

        $decoded = $this->decodeResponseBody($response);
        $typedDecoded = $this->normalizePayload($decoded);
        $selectedExternalBoxId = $this->extractSelectedPackedBinId($typedDecoded, count($products));
        if ($selectedExternalBoxId === null) {
            return null;
        }

        $selectedBox = $boxByExternalId[$selectedExternalBoxId] ?? null;
        if ($selectedBox === null) {
            $this->logger->error('[PackingAPI] Response returned unknown bin id.', [
                'binId' => $selectedExternalBoxId,
            ]);

            return null;
        }

        return $selectedBox;
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
     * @param list<Box> $boxes
     * @return array{0: array<string, mixed>, 1: array<string, Box>}
     */
    private function buildPayload(array $products, array $boxes): array
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

        $bins = [];
        $boxByExternalId = [];
        foreach ($boxes as $index => $box) {
            $externalId = sprintf('box-%d-%d', $box->getId() ?? 0, $index + 1);
            $bins[] = [
                'id' => $externalId,
                'w' => $box->getWidth(),
                'h' => $box->getHeight(),
                'd' => $box->getLength(),
                'max_wg' => $box->getMaxWeight(),
            ];
            $boxByExternalId[$externalId] = $box;
        }

        $payload = [
            'username' => $this->apiUsername,
            'api_key' => $this->apiKey,
            'bins' => $bins,
            'items' => $items,
            'params' => [
                'optimization_mode' => 'bins_number',
                'item_distribution' => false,
            ],
        ];

        return [$payload, $boxByExternalId];
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
    private function extractSelectedPackedBinId(array $payload, int $requestedItemCount): string|null
    {
        $responseNode = $this->extractResponseNode($payload);
        if ($responseNode === null) {
            return null;
        }

        $this->assertApiAccessAvailable($responseNode);

        $status = $responseNode['status'] ?? null;
        $binsPacked = $this->extractBinsPacked($responseNode);

        if ($binsPacked === []) {
            return null;
        }

        if (!$this->hasSinglePackedBin($binsPacked)) {
            return null;
        }

        if (!$this->hasNoNotPackedItems($responseNode)) {
            return null;
        }

        if ($status !== 1) {
            $this->logger->warning('[PackingAPI] Response status indicates failure.', ['status' => $status]);

            return null;
        }

        $items = $this->extractPackedItemsFromFirstBin($binsPacked);
        if ($items === null) {
            return null;
        }

        $packedCount = $this->countUniquePackedItemIds($items);
        if ($packedCount === null) {
            return null;
        }

        $canPack = $packedCount === $requestedItemCount;

        $this->logger->debug('[PackingAPI] Packability result', [
            'requestedItemCount' => $requestedItemCount,
            'packedCount' => $packedCount,
            'canPack' => $canPack,
        ]);

        if (!$canPack) {
            return null;
        }

        return $this->extractSelectedBinId($binsPacked);
    }

    /**
     * @param list<mixed> $binsPacked
     */
    private function extractSelectedBinId(array $binsPacked): string|null
    {
        $firstBin = $binsPacked[0];
        if (!is_array($firstBin)) {
            return null;
        }

        $binData = $firstBin['bin_data'] ?? null;
        if (!is_array($binData)) {
            $this->logger->error('[PackingAPI] Packed bin does not contain "bin_data".');

            return null;
        }

        $binId = $binData['id'] ?? null;
        if (!is_string($binId) || $binId === '') {
            $this->logger->error('[PackingAPI] Packed bin does not contain valid "bin_data.id".');

            return null;
        }

        return $binId;
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
     * @return list<mixed>
     */
    private function extractBinsPacked(array $responseNode): array
    {
        if (!array_key_exists('bins_packed', $responseNode)) {
            $this->logger->error('[PackingAPI] Response does not contain "bins_packed" key.');

            throw new ThirdPartyPackingException('Third-party packing API returned invalid response shape. Falling back to local calculation.');
        }

        $binsPacked = $responseNode['bins_packed'];
        if (!is_array($binsPacked)) {
            $this->logger->error('[PackingAPI] Response contains invalid "bins_packed" value type.');

            throw new ThirdPartyPackingException('Third-party packing API returned invalid response shape. Falling back to local calculation.');
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
