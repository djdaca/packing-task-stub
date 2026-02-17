<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Persistence;

use App\Packing\Application\Port\BoxCatalogPort;
use App\Packing\Domain\Entity\Packaging;
use App\Packing\Domain\Model\Box;

use function array_map;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Parameter;

final class DoctrineBoxCatalogAdapter implements BoxCatalogPort
{
    public function __construct(private EntityManager $entityManager)
    {
    }

    /**
     * Returns a specific box by ID, or null if not found.
     */
    public function findBox(int $id): Box|null
    {
        $packaging = $this->entityManager->find(Packaging::class, $id);
        if ($packaging === null) {
            return null;
        }

        return $this->toBox($packaging);
    }

    /**
     * @return list<Box>
     */
    public function getBoxesSuitableForDimensions(
        float $width,
        float $height,
        float $length,
        float $totalWeight
    ): array {
        // Input dimensions are already sorted (smallest to largest)
        /** @var list<Packaging> $packagings */
        $packagings = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Packaging::class, 'p')
            ->where(implode(' AND ', [
                'p.dimMin >= :dim1',
                'p.dimMid >= :dim2',
                'p.dimMax >= :dim3',
                'p.maxWeight >= :totalWeight',
            ]))
            ->setParameters(new ArrayCollection([
                new Parameter('dim1', $width),
                new Parameter('dim2', $height),
                new Parameter('dim3', $length),
                new Parameter('totalWeight', $totalWeight),
            ]))
            ->orderBy('p.width * p.height * p.length', 'ASC')
            ->addOrderBy('p.maxWeight', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            $this->toBox(...),
            $packagings,
        );
    }

    /**
     * Converts a Packaging entity to a Box domain model.
     */
    private function toBox(Packaging $packaging): Box
    {
        return new Box(
            $packaging->getId(),
            $packaging->getWidth(),
            $packaging->getHeight(),
            $packaging->getLength(),
            $packaging->getMaxWeight(),
        );
    }
}
