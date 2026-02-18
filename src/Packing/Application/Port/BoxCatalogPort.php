<?php

declare(strict_types=1);

namespace App\Packing\Application\Port;

use App\Packing\Domain\Model\Box;

interface BoxCatalogPort
{
    public const int DEFAULT_BATCH_SIZE = 50;

    /**
     * Returns a specific box by ID, or null if not found.
     */
    public function findBox(int $id): Box|null;

    /**
     * Returns one page of suitable boxes sorted by volume (smallest first), then ID.
     * Input dimensions must be sorted ascending.
     *
     * @return list<Box>
     */
    public function getBoxesSuitableForDimensionsBatch(
        float $width,
        float $height,
        float $length,
        float $totalWeight,
        int $limit,
        float|null $lastVolume = null,
        int|null $lastId = null,
    ): array;
}
