<?php

declare(strict_types=1);

namespace App\Packing\Application\Port;

use App\Packing\Domain\Model\Box;

interface BoxCatalogPort
{
    /**
     * Returns all available boxes sorted by volume (smallest first), then by max weight.
     * @return list<Box>
     */
    public function getAllBoxes(): array;
}
