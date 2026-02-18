<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function count;

use Psr\Log\LoggerInterface;

final class ResilientPackabilityChecker implements PackabilityCheckerPort
{
    public function __construct(
        private PackabilityCheckerPort $primary,
        private PackabilityCheckerPort $fallback,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    public function findFirstPackableBox(array $products, array $boxes): Box|null
    {
        try {
            $this->logger->debug('[ResilientPackabilityChecker] Using primary checker for batch', [
                'candidateCount' => count($boxes),
            ]);

            return $this->primary->findFirstPackableBox($products, $boxes);
        } catch (ThirdPartyPackingException $exception) {
            $this->logger->warning('[ResilientPackabilityChecker] Primary checker failed for batch, using fallback', [
                'error' => $exception->getMessage(),
                'candidateCount' => count($boxes),
            ]);

            return $this->fallback->findFirstPackableBox($products, $boxes);
        }
    }
}
