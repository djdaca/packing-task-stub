<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize\Dto;

final readonly class Item
{
    public function __construct(
        public string $id,
        public float $width,
        public float $height,
        public float $depth,
        public float $weight,
        public int $quantity,
    ) {
    }

    /**
     * @return array{id: string, w: float, h: float, d: float, wg: float, q: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'w' => $this->width,
            'h' => $this->height,
            'd' => $this->depth,
            'wg' => $this->weight,
            'q' => $this->quantity,
        ];
    }
}
