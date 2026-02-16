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

    #[ORM\Column(type: Types::INTEGER, name: 'selected_box_id', nullable: true)]
    private int|null $selectedBoxId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $hash, int|null $selectedBoxId = null)
    {
        $now = new DateTimeImmutable();
        $this->id = $hash;
        $this->selectedBoxId = $selectedBoxId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getSelectedBoxId(): int|null
    {
        return $this->selectedBoxId;
    }

    public function setSelectedBoxId(int|null $selectedBoxId): void
    {
        $this->selectedBoxId = $selectedBoxId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
