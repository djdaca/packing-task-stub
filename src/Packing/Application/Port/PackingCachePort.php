<?php

declare(strict_types=1);

namespace App\Packing\Application\Port;

use App\Packing\Domain\Model\Product;

interface PackingCachePort
{
    /**
     * @param list<Product> $products
     */
    public function getSelectedBox(array $products): int|null;

    /**
     * @param list<Product> $products
     */
    public function storeSelectedBox(array $products, int $selectedBoxId): void;
}
