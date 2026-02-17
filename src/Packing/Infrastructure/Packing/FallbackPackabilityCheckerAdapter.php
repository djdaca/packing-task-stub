<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use Psr\Log\LoggerInterface;

final class FallbackPackabilityCheckerAdapter implements PackabilityCheckerPort
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @param list<Product> $products
     */
    public function canPackIntoBox(array $products, Box $box): bool
    {
        $boxDims = $box->sortedDimensions();

        $totalProductVolume = 0.0;
        $totalWeight = 0.0;

        foreach ($products as $product) {
            $productDims = $product->sortedDimensions();

            if (!$this->dimensionsFit($productDims, $boxDims)) {
                $this->logger->info('[PackingAPI][Fallback] Product does not fit in box.', [
                    'productDims' => $productDims,
                    'boxDims' => $boxDims,
                    'boxId' => $box->getId(),
                ]);

                return false;
            }

            $totalWeight += $product->getWeight();
            $totalProductVolume += $product->volume();
        }

        if ($totalWeight > $box->getMaxWeight()) {
            $this->logger->info('[PackingAPI][Fallback] Total weight exceeds box maxWeight.', [
                'totalWeight' => $totalWeight,
                'maxWeight' => $box->getMaxWeight(),
                'boxId' => $box->getId(),
            ]);

            return false;
        }

        $result = $totalProductVolume <= $box->volume();
        $this->logger->info('[PackingAPI][Fallback] Packability result.', [
            'result' => $result,
            'totalProductVolume' => $totalProductVolume,
            'boxVolume' => $box->volume(),
            'boxId' => $box->getId(),
        ]);

        return $result;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $productDims
     * @param array{0: float, 1: float, 2: float} $boxDims
     */
    private function dimensionsFit(array $productDims, array $boxDims): bool
    {
        return $productDims[0] <= $boxDims[0]
            && $productDims[1] <= $boxDims[1]
            && $productDims[2] <= $boxDims[2];
    }
}
