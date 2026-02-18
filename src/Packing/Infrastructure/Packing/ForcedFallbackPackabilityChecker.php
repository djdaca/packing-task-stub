<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

final class ForcedFallbackPackabilityChecker implements PackabilityCheckerPort
{
    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    public function findFirstPackableBox(array $products, array $boxes): Box|null
    {
        throw new ThirdPartyPackingException('Forced fallback for deterministic tests.');
    }
}
