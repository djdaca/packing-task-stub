<?php

declare(strict_types=1);

namespace App\Packing\Application\Port;

use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

interface PackabilityCheckerPort
{
    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    public function findFirstPackableBox(array $products, array $boxes): Box|null;
}
