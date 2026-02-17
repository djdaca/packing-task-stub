<?php

declare(strict_types=1);

namespace App\Packing\Application\Port;

use App\Packing\Domain\Model\Box;

interface BoxCatalogPort
{
    /**
     * Returns a specific box by ID, or null if not found.
     */
    public function findBox(int $id): Box|null;

    /**
     * Returns boxes suitable for given dimensions, sorted by volume (smallest first).
     * Filters by minimum dimensions and maximum weight capability.
     * @return list<Box>
     */
    public function getBoxesSuitableForDimensions(
        float $width,
        float $height,
        float $length,
        float $totalWeight
    ): array;
}
