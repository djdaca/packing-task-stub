<?php

declare(strict_types=1);

namespace App\Packing\Domain\Model;

use App\Packing\Domain\Exception\DomainValidationException;

use function sprintf;

final class Product
{
    private const float MIN_DIMENSION = 0.1;
    private const float MAX_DIMENSION = 1000.0;
    private const float MAX_WEIGHT = 10000.0;

    public function __construct(
        private float $width,
        private float $height,
        private float $length,
        private float $weight,
    ) {
        $this->width = $this->assertWithinBounds($this->width, 'width');
        $this->height = $this->assertWithinBounds($this->height, 'height');
        $this->length = $this->assertWithinBounds($this->length, 'length');
        $this->weight = $this->assertWeightWithinBounds($this->weight, 'weight');
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

    public function getWeight(): float
    {
        return $this->weight;
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

    public function volume(): float
    {
        return $this->width * $this->height * $this->length;
    }

    /**
     * @return array{width: float, height: float, length: float, weight: float}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'length' => $this->length,
            'weight' => $this->weight,
        ];
    }

    private function assertWithinBounds(float $value, string $field): float
    {
        if ($value < self::MIN_DIMENSION) {
            throw new DomainValidationException(
                sprintf('Product field "%s" must be >= %.1f (got %.2f).', $field, self::MIN_DIMENSION, $value)
            );
        }
        if ($value > self::MAX_DIMENSION) {
            throw new DomainValidationException(
                sprintf('Product field "%s" must be <= %.0f (got %.2f).', $field, self::MAX_DIMENSION, $value)
            );
        }

        return $value;
    }

    private function assertWeightWithinBounds(float $value, string $field): float
    {
        if ($value <= 0.0) {
            throw new DomainValidationException(sprintf('Product field "%s" must be > 0.', $field));
        }
        if ($value > self::MAX_WEIGHT) {
            throw new DomainValidationException(
                sprintf('Product field "%s" must be <= %.0f kg (got %.2f).', $field, self::MAX_WEIGHT, $value)
            );
        }

        return $value;
    }
}
