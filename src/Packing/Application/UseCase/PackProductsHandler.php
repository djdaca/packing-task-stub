<?php

declare(strict_types=1);

namespace App\Packing\Application\UseCase;

use App\Packing\Application\Port\BoxCatalogPort;
use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function count;

use Psr\Log\LoggerInterface;

final class PackProductsHandler
{
    public function __construct(
        private BoxCatalogPort $boxCatalog,
        private PackabilityCheckerPort $packabilityChecker,
        private PackingCachePort $cache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param list<Product> $products
     */
    public function handle(array $products): Box|null
    {
        $this->logger->info('[PackProductsHandler] Starting box selection', [
            'productCount' => count($products),
        ]);

        // Calculate product requirements
        $requirements = $this->calculateProductRequirements($products);

        $this->logger->debug('[PackProductsHandler] Product requirements', [
            'maxWidth' => $requirements['maxWidth'],
            'maxHeight' => $requirements['maxHeight'],
            'maxLength' => $requirements['maxLength'],
            'totalWeight' => $requirements['totalWeight'],
        ]);

        // Check cache first
        $cachedBoxId = $this->cache->getSelectedBox($products);
        if ($cachedBoxId !== null) {
            $cachedBox = $this->boxCatalog->findBox($cachedBoxId);
            if ($cachedBox !== null) {
                $this->logger->info('[PackProductsHandler] Box selected from cache', ['boxId' => $cachedBox->getId()]);

                return $cachedBox;
            }
        }

        // Get suitable boxes filtered by dimensions/weight
        $lastVolume = null;
        $lastId = null;
        $scannedCandidates = 0;

        while (true) {
            $boxes = $this->boxCatalog->getBoxesSuitableForDimensionsBatch(
                $requirements['maxWidth'],
                $requirements['maxHeight'],
                $requirements['maxLength'],
                $requirements['totalWeight'],
                BoxCatalogPort::DEFAULT_BATCH_SIZE,
                $lastVolume,
                $lastId,
            );

            if ($boxes === []) {
                break;
            }

            $this->logger->debug('[PackProductsHandler] Suitable boxes batch', ['count' => count($boxes)]);

            $selectedBox = $this->packabilityChecker->findFirstPackableBox($products, $boxes);
            if ($selectedBox !== null) {
                $selectedPosition = $this->findSelectedBoxPosition($boxes, $selectedBox);
                $scannedCandidates += $selectedPosition;

                $this->logger->debug('[PackProductsHandler] Packability check result', [
                    'boxId' => $selectedBox->getId(),
                    'canPack' => true,
                ]);
                $this->logger->info('[PackProductsHandler] Box selected', [
                    'boxId' => $selectedBox->getId(),
                    'scannedCandidates' => $scannedCandidates,
                ]);

                return $selectedBox;
            }

            $scannedCandidates += count($boxes);

            $lastBox = $boxes[count($boxes) - 1];
            $lastVolume = $lastBox->volume();
            $lastId = $lastBox->getId();

            if ($lastId === null) {
                break;
            }
        }

        $this->logger->warning('[PackProductsHandler] No suitable box found');

        return null;
    }

    /**
     * @param list<Box> $boxes
     */
    private function findSelectedBoxPosition(array $boxes, Box $selectedBox): int
    {
        foreach ($boxes as $index => $box) {
            if ($box->getId() !== null && $box->getId() === $selectedBox->getId()) {
                return $index + 1;
            }
        }

        return count($boxes);
    }

    /**
     * @param list<Product> $products
     * @return array{maxWidth: float, maxHeight: float, maxLength: float, totalWeight: float}
     */
    private function calculateProductRequirements(array $products): array
    {
        $maxSortedDims = [0.0, 0.0, 0.0];
        $totalWeight = 0.0;

        foreach ($products as $product) {
            $sortedDims = $product->sortedDimensions();
            $maxSortedDims[0] = max($maxSortedDims[0], $sortedDims[0]);
            $maxSortedDims[1] = max($maxSortedDims[1], $sortedDims[1]);
            $maxSortedDims[2] = max($maxSortedDims[2], $sortedDims[2]);
            $totalWeight += $product->getWeight();
        }

        return [
            'maxWidth' => $maxSortedDims[0],
            'maxHeight' => $maxSortedDims[1],
            'maxLength' => $maxSortedDims[2],
            'totalWeight' => $totalWeight,
        ];
    }
}
