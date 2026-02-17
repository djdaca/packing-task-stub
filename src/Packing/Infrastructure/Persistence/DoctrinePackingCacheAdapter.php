<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Persistence;

use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Entity\PackingCalculationCache;
use App\Packing\Domain\Model\Product;

use function array_map;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

        // Check if already cached before attempting insert
        $cached = $this->findCacheEntry($hash);
        if ($cached !== null) {
            $this->logger->debug('[PackingCache] Cache entry already exists; skipping');

            return;
        }

        // Attempt insert; ignore if race condition occurs
        try {
            $this->createCacheEntry($hash, $selectedBoxId);
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('[PackingCache] Race condition detected; another request cached same hash');
        }
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
        // Refresh entity manager to ensure fresh query from DB
        $this->entityManager->clear();

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
                'dims' => array_map(static fn (float $value): string => number_format($value, 2, '.', ''), $dims),
                'weight' => number_format($product->getWeight(), 2, '.', ''),
            ];
        }, $products);

        usort($normalizedProducts, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));

        return hash('sha256', json_encode([
            'products' => $normalizedProducts,
        ], JSON_THROW_ON_ERROR));
    }

    // Cache entries are not expired automatically; cleanup is manual if ever needed.
}
