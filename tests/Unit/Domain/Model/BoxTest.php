<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Model;

use App\Packing\Domain\Exception\DomainValidationException;
use App\Packing\Domain\Model\Box;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Box::class)]
#[CoversClass(DomainValidationException::class)]
final class BoxTest extends TestCase
{
    public function testCreatesBoxWithValidDimensions(): void
    {
        $box = new Box(1, 5.0, 3.0, 4.0, 20.0);

        self::assertSame(1, $box->getId());
        self::assertSame(5.0, $box->getWidth());
        self::assertSame(3.0, $box->getHeight());
        self::assertSame(4.0, $box->getLength());
        self::assertSame(20.0, $box->getMaxWeight());
    }

    public function testCalculatesVolume(): void
    {
        $box = new Box(1, 2.0, 3.0, 4.0, 10.0);

        self::assertSame(24.0, $box->volume());
    }

    public function testSortedDimensionsReturnsSortedArray(): void
    {
        $box = new Box(1, 5.0, 2.0, 8.0, 10.0);

        $sorted = $box->sortedDimensions();

        self::assertSame([2.0, 5.0, 8.0], $sorted);
    }

    public function testSortedDimensionsWithEqualValues(): void
    {
        $box = new Box(1, 3.0, 3.0, 3.0, 10.0);

        $sorted = $box->sortedDimensions();

        self::assertSame([3.0, 3.0, 3.0], $sorted);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $box = new Box(1, 5.0, 3.0, 4.0, 20.0);

        $array = $box->toArray();

        self::assertSame([
            'id' => 1,
            'width' => 5.0,
            'height' => 3.0,
            'length' => 4.0,
            'maxWeight' => 20.0,
        ], $array);
    }

    public function testThrowsWhenWidthIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessage('Box field "width" must be > 0.');

        new Box(1, 0.0, 3.0, 4.0, 10.0);
    }

    public function testThrowsWhenWidthIsNegative(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessage('Box field "width" must be > 0.');

        new Box(1, -5.0, 3.0, 4.0, 10.0);
    }

    public function testThrowsWhenHeightIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessage('Box field "height" must be > 0.');

        new Box(1, 5.0, 0.0, 4.0, 10.0);
    }

    public function testThrowsWhenLengthIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessage('Box field "length" must be > 0.');

        new Box(1, 5.0, 3.0, 0.0, 10.0);
    }

    public function testThrowsWhenMaxWeightIsZero(): void
    {
        $this->expectException(DomainValidationException::class);
        $this->expectExceptionMessage('Box field "maxWeight" must be > 0.');

        new Box(1, 5.0, 3.0, 4.0, 0.0);
    }

    public function testAllowsNullId(): void
    {
        $box = new Box(null, 5.0, 3.0, 4.0, 10.0);

        self::assertNull($box->getId());
    }
}
