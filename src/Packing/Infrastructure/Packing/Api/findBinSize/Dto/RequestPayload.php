<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize\Dto;

use function array_map;

final readonly class RequestPayload
{
    /**
     * @param list<Bin> $bins
     * @param list<Item> $items
     */
    public function __construct(
        public string $username,
        public string $apiKey,
        public array $bins,
        public array $items,
        public Params $params,
    ) {
    }

    /**
     * @return array{username: string, api_key: string, bins: list<array{id: string, w: float, h: float, d: float, max_wg: float}>, items: list<array{id: string, w: float, h: float, d: float, wg: float, q: int}>, params: array{optimization_mode: string, item_distribution: bool}}
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'api_key' => $this->apiKey,
            'bins' => array_map(static fn (Bin $bin): array => $bin->toArray(), $this->bins),
            'items' => array_map(static fn (Item $item): array => $item->toArray(), $this->items),
            'params' => $this->params->toArray(),
        ];
    }
}
