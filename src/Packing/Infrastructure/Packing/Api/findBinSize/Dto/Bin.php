<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize\Dto;

final readonly class Bin
{
    public function __construct(
        public string $id,
        public float $width,
        public float $height,
        public float $depth,
        public float $maxWeight,
    ) {
    }

    /**
     * @return array{id: string, w: float, h: float, d: float, max_wg: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'w' => $this->width,
            'h' => $this->height,
            'd' => $this->depth,
            'max_wg' => $this->maxWeight,
        ];
    }
}
