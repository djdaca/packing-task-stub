<?php

declare(strict_types=1);

namespace App\Packing\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'packing_calculation_cache')]
class PackingCalculationCache
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER, name: 'selected_box_id')]
    private int $selectedBoxId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $hash, int $selectedBoxId)
    {
        $this->id = $hash;
        $this->selectedBoxId = $selectedBoxId;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getSelectedBoxId(): int
    {
        return $this->selectedBoxId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
