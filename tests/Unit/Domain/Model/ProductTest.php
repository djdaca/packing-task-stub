<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Model;

use App\Packing\Domain\Exception\DomainValidationException;
use App\Packing\Domain\Model\Product;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Product::class)]
#[CoversClass(DomainValidationException::class)]
final class ProductTest extends TestCase
{
    public function testCreatesProductWithValidDimensions(): void
    {
        $product = new Product(5.0, 3.0, 4.0, 2.5);

        self::assertSame(5.0, $product->getWidth());
        self::assertSame(3.0, $product->getHeight());
        self::assertSame(4.0, $product->getLength());
        self::assertSame(2.5, $product->getWeight());
    }

    public function testCalculatesVolume(): void
    {
        $product = new Product(2.0, 3.0, 4.0, 1.0);

        self::assertSame(24.0, $product->volume());
    }

    public function testSortedDimensionsReturnsSortedArray(): void
    {
        $product = new Product(5.0, 2.0, 8.0, 1.0);

        $sorted = $product->sortedDimensions();

        self::assertSame([2.0, 5.0, 8.0], $sorted);
    }

    public function testSortedDimensionsWithEqualValues(): void
    {
        $product = new Product(3.0, 3.0, 3.0, 1.0);

        $sorted = $product->sortedDimensions();

        self::assertSame([3.0, 3.0, 3.0], $sorted);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $product = new Product(5.0, 3.0, 4.0, 2.5);

        $array = $product->toArray();

        self::assertSame([
            'width' => 5.0,
            'height' => 3.0,
            'length' => 4.0,
            'weight' => 2.5,
        ], $array);
    }

    public function testThrowsWhenWidthIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "width" must be.*/');

        new Product(0.0, 3.0, 4.0, 1.0);
    }

    public function testThrowsWhenWidthIsNegative(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "width" must be.*/');

        new Product(-5.0, 3.0, 4.0, 1.0);
    }

    public function testThrowsWhenHeightIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "height" must be.*/');

        new Product(5.0, 0.0, 4.0, 1.0);
    }

    public function testThrowsWhenLengthIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "length" must be.*/');

        new Product(5.0, 3.0, 0.0, 1.0);
    }

    public function testThrowsWhenWeightIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "weight" must be.*/');

        new Product(5.0, 3.0, 4.0, 0.0);
    }

    public function testThrowsWhenWeightIsNegative(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "weight" must be.*/');

        new Product(5.0, 3.0, 4.0, -1.0);
    }

    public function testThrowsWhenDimensionIsTooLarge(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "width" must be <= \d+/');

        new Product(2000.0, 3.0, 4.0, 1.0);
    }

    public function testThrowsWhenWeightIsTooLarge(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessageMatches('/Product field "weight" must be <= \d+/');

        new Product(5.0, 3.0, 4.0, 50000.0);
    }
}
