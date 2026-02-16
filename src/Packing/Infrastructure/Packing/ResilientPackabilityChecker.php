<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
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
     */
    public function canPackIntoBox(array $products, Box $box): bool
    {
        try {
            $this->logger->debug('[ResilientPackabilityChecker] Using primary checker', ['boxId' => $box->getId()]);

            return $this->primary->canPackIntoBox($products, $box);
        } catch (ThirdPartyPackingException $exception) {
            $this->logger->warning('[ResilientPackabilityChecker] Primary checker failed, using fallback', [
                'boxId' => $box->getId(),
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->canPackIntoBox($products, $box);
        }
    }
}
