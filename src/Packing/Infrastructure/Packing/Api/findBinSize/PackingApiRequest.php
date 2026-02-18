<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize;

use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\Bin;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\Item;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\Params;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\RequestPayload;

use function sprintf;

final class PackingApiRequest
{
    /** @param array<string, Box> $boxByExternalId */
    private function __construct(
        private RequestPayload $payload,
        private array $boxByExternalId,
    ) {
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    public static function fromDomain(array $products, array $boxes, string $username, string $apiKey): self
    {
        $items = [];
        foreach ($products as $index => $product) {
            $items[] = new Item(
                id: sprintf('item-%d', $index + 1),
                width: $product->getWidth(),
                height: $product->getHeight(),
                depth: $product->getLength(),
                weight: $product->getWeight(),
                quantity: 1,
            );
        }

        $bins = [];
        $boxByExternalId = [];
        foreach ($boxes as $index => $box) {
            $externalId = sprintf('box-%d-%d', $box->getId() ?? 0, $index + 1);
            $bins[] = new Bin(
                id: $externalId,
                width: $box->getWidth(),
                height: $box->getHeight(),
                depth: $box->getLength(),
                maxWeight: $box->getMaxWeight(),
            );
            $boxByExternalId[$externalId] = $box;
        }

        $payload = new RequestPayload(
            username: $username,
            apiKey: $apiKey,
            bins: $bins,
            items: $items,
            params: new Params(
                optimizationMode: 'bins_number',
                itemDistribution: false,
            ),
        );

        return new self($payload, $boxByExternalId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload->toArray();
    }

    /**
     * @return array<string, Box>
     */
    public function boxByExternalId(): array
    {
        return $this->boxByExternalId;
    }
}
