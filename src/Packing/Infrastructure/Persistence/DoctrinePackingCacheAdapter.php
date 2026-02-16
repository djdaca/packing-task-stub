<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Persistence;

use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Entity\PackingCalculationCache;
use App\Packing\Domain\Model\Product;

use function array_map;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;

use function hash;
use function json_encode;
use function number_format;

use Psr\Log\LoggerInterface;

use function strcmp;
use function usort;

final class DoctrinePackingCacheAdapter implements PackingCachePort
{
    public function __construct(
        private EntityManager $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param list<Product> $products
     */
    public function getSelectedBox(array $products): int|null
    {
        $hash = $this->buildHash($products);
        $this->logger->debug('[PackingCache] Checking for cached result', ['hash' => $hash]);

        // Refresh entity manager to ensure fresh query from DB
        $this->entityManager->clear();

        $cached = $this->findCacheEntry($hash);

        if ($cached !== null) {
            $this->logger->debug('[PackingCache] Cache hit', [
                'selectedBoxId' => $cached->getSelectedBoxId(),
            ]);

            return $cached->getSelectedBoxId();
        }

        $this->logger->debug('[PackingCache] Cache miss');

        return null;
    }

    /**
     * @param list<Product> $products
     */
    public function storeSelectedBox(array $products, int $selectedBoxId): void
    {
        $hash = $this->buildHash($products);
        $this->logger->debug('[PackingCache] Storing cache result', [
            'hash' => $hash,
            'selectedBoxId' => $selectedBoxId,
        ]);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Ensure we see the latest state inside the transaction
            $this->entityManager->clear();

            $cached = $this->findCacheEntry($hash);

            if ($cached !== null) {
                $this->updateCacheEntry($cached, $selectedBoxId, 'Updated existing cache entry');
                $connection->commit();

                return;
            }

            try {
                $this->createCacheEntry($hash, $selectedBoxId);
                $connection->commit();
            } catch (UniqueConstraintViolationException) {
                // Another request inserted the same hash in parallel
                $this->entityManager->clear();
                $existing = $this->findCacheEntry($hash);
                if ($existing !== null) {
                    $this->updateCacheEntry($existing, $selectedBoxId, 'Race detected, updated existing cache entry', [
                        'hash' => $hash,
                    ]);
                }
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function updateCacheEntry(
        PackingCalculationCache $cached,
        int $selectedBoxId,
        string $message,
        array $context = []
    ): void {
        $this->entityManager->lock($cached, LockMode::PESSIMISTIC_WRITE);
        $cached->setSelectedBoxId($selectedBoxId);
        $this->entityManager->flush();
        $this->logger->info('[PackingCache] ' . $message, $context);
    }

    private function createCacheEntry(string $hash, int $selectedBoxId): void
    {
        $cached = new PackingCalculationCache($hash, $selectedBoxId);
        $this->entityManager->persist($cached);
        $this->entityManager->flush();
        $this->logger->debug('[PackingCache] Created new cache entry');
    }

    private function findCacheEntry(string $hash): PackingCalculationCache|null
    {
        return $this->entityManager
            ->getRepository(PackingCalculationCache::class)
            ->find($hash);
    }

    /**
     * @param list<Product> $products
     */
    private function buildHash(array $products): string
    {
        $normalizedProducts = array_map(static function (Product $product): array {
            $dims = $product->sortedDimensions();

            return [
                'dims' => array_map(static fn (float $value): string => number_format($value, 6, '.', ''), $dims),
                'weight' => number_format($product->getWeight(), 6, '.', ''),
            ];
        }, $products);

        usort($normalizedProducts, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));

        return hash('sha256', json_encode([
            'products' => $normalizedProducts,
        ], JSON_THROW_ON_ERROR));
    }

    // Cache entries are not expired automatically; cleanup is manual if ever needed.
}
