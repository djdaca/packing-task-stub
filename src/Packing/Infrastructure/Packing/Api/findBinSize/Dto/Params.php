<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize\Dto;

final readonly class Params
{
    public function __construct(
        public string $optimizationMode,
        public bool $itemDistribution,
    ) {
    }

    /**
     * @return array{optimization_mode: string, item_distribution: bool}
     */
    public function toArray(): array
    {
        return [
            'optimization_mode' => $this->optimizationMode,
            'item_distribution' => $this->itemDistribution,
        ];
    }
}
