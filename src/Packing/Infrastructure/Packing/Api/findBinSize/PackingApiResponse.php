<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize;

use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\ResponsePayload;
use App\Packing\Infrastructure\Packing\ThirdPartyPackingException;

use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

use Psr\Http\Message\ResponseInterface;

use function sprintf;
use function strtolower;

final class PackingApiResponse
{
    private const string INVALID_RESPONSE_SHAPE_MESSAGE = 'Third-party packing API returned invalid response shape. Falling back to local calculation.';

    /** @var list<mixed>|null */
    private array|null $validatedBinsPacked = null;

    private function __construct(
        private ResponsePayload $payload,
    ) {
    }

    public static function fromHttpResponse(ResponseInterface $response): self|null
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

        if (!is_array($decoded)) {
            throw new ThirdPartyPackingException('Third-party packing API returned invalid payload shape.');
        }

        $responseNode = $decoded['response'] ?? null;
        if (!is_array($responseNode)) {
            return null;
        }

        /** @var array<string, mixed> $responseNode */
        return new self(ResponsePayload::fromResponseNode($responseNode));
    }

    public function selectedPackedBinId(int $requestedItemCount): string|null
    {
        $this->assertApiAccessAvailable();

        $status = $this->payload->status;
        $binsPacked = $this->binsPacked();
        if ($binsPacked === [] || count($binsPacked) !== 1) {
            return null;
        }

        if (!$this->hasNoNotPackedItems()) {
            return null;
        }

        if ($status !== 1) {
            return null;
        }

        $items = $this->extractPackedItemsFromFirstBin();
        if ($items === null) {
            return null;
        }

        $packedCount = $this->countUniquePackedItemIds($items);
        if ($packedCount === null || $packedCount !== $requestedItemCount) {
            return null;
        }

        return $this->extractSelectedBinId();
    }

    /**
     * @return list<mixed>
     */
    public function binsPacked(): array
    {
        if ($this->validatedBinsPacked !== null) {
            return $this->validatedBinsPacked;
        }

        if (!$this->payload->hasBinsPacked) {
            throw new ThirdPartyPackingException(self::INVALID_RESPONSE_SHAPE_MESSAGE);
        }

        $binsPacked = $this->payload->binsPacked;
        if (!is_array($binsPacked)) {
            throw new ThirdPartyPackingException(self::INVALID_RESPONSE_SHAPE_MESSAGE);
        }

        $this->validatedBinsPacked = array_values($binsPacked);

        return $this->validatedBinsPacked;
    }

    private function assertApiAccessAvailable(): void
    {
        $status = $this->payload->status;
        if (is_int($status) && $status < 0) {
            throw new ThirdPartyPackingException(
                sprintf('Third-party API application error (status %d). Falling back to local calculation.', $status)
            );
        }

        foreach ($this->payload->errors as $error) {
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
                throw new ThirdPartyPackingException('Third-party API access blocked. Falling back to local calculation.');
            }
        }
    }

    private function hasNoNotPackedItems(): bool
    {
        if (!is_array($this->payload->notPackedItems)) {
            return false;
        }

        return count($this->payload->notPackedItems) === 0;
    }

    /**
     * @return list<mixed>|null
     */
    private function extractPackedItemsFromFirstBin(): array|null
    {
        $binsPacked = $this->binsPacked();
        $firstBin = $binsPacked[0] ?? null;
        if (!is_array($firstBin)) {
            return null;
        }

        $items = $firstBin['items'] ?? null;
        if (!is_array($items)) {
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
                return null;
            }

            $itemId = $item['id'] ?? null;
            if (!is_string($itemId) || $itemId === '') {
                return null;
            }

            $packedItemIds[] = $itemId;
        }

        return count(array_unique($packedItemIds));
    }

    private function extractSelectedBinId(): string|null
    {
        $binsPacked = $this->binsPacked();
        $firstBin = $binsPacked[0] ?? null;
        if (!is_array($firstBin)) {
            return null;
        }

        $binData = $firstBin['bin_data'] ?? null;
        if (!is_array($binData)) {
            return null;
        }

        $binId = $binData['id'] ?? null;
        if (!is_string($binId) || $binId === '') {
            return null;
        }

        return $binId;
    }
}
