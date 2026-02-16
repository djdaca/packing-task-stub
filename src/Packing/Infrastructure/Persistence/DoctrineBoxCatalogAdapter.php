<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Persistence;

use App\Packing\Application\Port\BoxCatalogPort;
use App\Packing\Domain\Entity\Packaging;
use App\Packing\Domain\Model\Box;

use function array_map;

use Doctrine\ORM\EntityManager;

final class DoctrineBoxCatalogAdapter implements BoxCatalogPort
{
    public function __construct(private EntityManager $entityManager)
    {
    }

    /**
     * @return list<Box>
     */
    public function getAllBoxes(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('p')
            ->from(Packaging::class, 'p')
            ->orderBy('p.width * p.height * p.length', 'ASC')
            ->addOrderBy('p.maxWeight', 'ASC');

        /** @var list<Packaging> $packagings */
        $packagings = $qb->getQuery()->getResult();

        return array_map(
            static fn (Packaging $packaging): Box => new Box(
                $packaging->getId(),
                $packaging->getWidth(),
                $packaging->getHeight(),
                $packaging->getLength(),
                $packaging->getMaxWeight()
            ),
            $packagings
        );
    }
}
