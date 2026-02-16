<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Persistence;

use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Entity\PackingCalculationCache;
use App\Packing\Domain\Model\Product;

use function array_map;

use DateTimeImmutable;
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
        private LoggerInterface $logger,
        private int $ttlSeconds = 0
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

        /** @var PackingCalculationCache|null $cached */
        $cached = $this->entityManager
            ->getRepository(PackingCalculationCache::class)
            ->find($hash);

        if ($cached !== null) {
            if ($this->isExpired($cached)) {
                $this->logger->info('[PackingCache] Cache entry expired', [
                    'hash' => $hash,
                ]);
                $this->entityManager->remove($cached);
                $this->entityManager->flush();

                return null;
            }

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
            $repository = $this->entityManager->getRepository(PackingCalculationCache::class);

            /** @var PackingCalculationCache|null $cached */
            $cached = $repository->find($hash);

            if ($cached !== null) {
                $this->entityManager->lock($cached, LockMode::PESSIMISTIC_WRITE);
                $cached->setSelectedBoxId($selectedBoxId);
                $this->entityManager->flush();
                $this->logger->debug('[PackingCache] Updated existing cache entry');
                $connection->commit();

                return;
            }

            $cached = new PackingCalculationCache($hash, $selectedBoxId);
            $this->entityManager->persist($cached);

            try {
                $this->entityManager->flush();
                $this->logger->debug('[PackingCache] Created new cache entry');
                $connection->commit();
            } catch (UniqueConstraintViolationException) {
                // Another request inserted the same hash in parallel
                $this->entityManager->clear();
                $repository = $this->entityManager->getRepository(PackingCalculationCache::class);
                /** @var PackingCalculationCache|null $existing */
                $existing = $repository->find($hash);
                if ($existing !== null) {
                    $this->entityManager->lock($existing, LockMode::PESSIMISTIC_WRITE);
                    $existing->setSelectedBoxId($selectedBoxId);
                    $this->entityManager->flush();
                    $this->logger->info('[PackingCache] Race detected, updated existing cache entry', [
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

    private function isExpired(PackingCalculationCache $cached): bool
    {
        if ($this->ttlSeconds <= 0) {
            return false;
        }

        $ageSeconds = (new DateTimeImmutable())->getTimestamp() - $cached->getUpdatedAt()->getTimestamp();

        return $ageSeconds > $this->ttlSeconds;
    }
}
