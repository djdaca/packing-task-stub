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
        $maxWidth = 0.0;
        $maxHeight = 0.0;
        $maxLength = 0.0;
        $totalWeight = 0.0;
        foreach ($products as $product) {
            $maxWidth = max($maxWidth, $product->getWidth());
            $maxHeight = max($maxHeight, $product->getHeight());
            $maxLength = max($maxLength, $product->getLength());
            $totalWeight += $product->getWeight();
        }

        $this->logger->debug('[PackProductsHandler] Product requirements', [
            'maxWidth' => $maxWidth,
            'maxHeight' => $maxHeight,
            'maxLength' => $maxLength,
            'totalWeight' => $totalWeight,
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
        $boxes = $this->boxCatalog->getBoxesSuitableForDimensions($maxWidth, $maxHeight, $maxLength, (int) $totalWeight);
        $this->logger->debug('[PackProductsHandler] Suitable boxes', ['count' => count($boxes)]);

        foreach ($boxes as $box) {
            $this->logger->debug('[PackProductsHandler] Checking packability', ['boxId' => $box->getId()]);
            $canPack = $this->packabilityChecker->canPackIntoBox($products, $box);
            $this->logger->debug('[PackProductsHandler] Packability check result', [
                'boxId' => $box->getId(),
                'canPack' => $canPack,
            ]);

            if ($canPack) {
                $this->logger->info('[PackProductsHandler] Box selected', ['boxId' => $box->getId()]);

                return $box;
            }
        }

        $this->logger->warning('[PackProductsHandler] No suitable box found');

        return null;
    }
}
