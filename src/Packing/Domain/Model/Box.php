<?php

declare(strict_types=1);

namespace App\Packing\Domain\Model;

use App\Packing\Domain\Exception\DomainValidationException;

use function sort;
use function sprintf;

final class Box
{
    public function __construct(
        private int|null $id,
        private float $width,
        private float $height,
        private float $length,
        private float $maxWeight,
    ) {
        $this->width = $this->assertPositive($this->width, 'width');
        $this->height = $this->assertPositive($this->height, 'height');
        $this->length = $this->assertPositive($this->length, 'length');
        $this->maxWeight = $this->assertPositive($this->maxWeight, 'maxWeight');
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getLength(): float
    {
        return $this->length;
    }

    public function getMaxWeight(): float
    {
        return $this->maxWeight;
    }

    public function volume(): float
    {
        return $this->width * $this->height * $this->length;
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function sortedDimensions(): array
    {
        $dims = [$this->width, $this->height, $this->length];
        sort($dims);

        return [$dims[0], $dims[1], $dims[2]];
    }

    /**
     * @return array{id: int|null, width: float, height: float, length: float, maxWeight: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'width' => $this->width,
            'height' => $this->height,
            'length' => $this->length,
            'maxWeight' => $this->maxWeight,
        ];
    }

    private function assertPositive(float $value, string $field): float
    {
        if ($value <= 0.0) {
            throw new DomainValidationException(sprintf('Box field "%s" must be > 0.', $field));
        }

        return $value;
    }
}
