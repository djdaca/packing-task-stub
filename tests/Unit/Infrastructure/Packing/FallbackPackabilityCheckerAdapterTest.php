<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Packing;

use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\FallbackPackabilityCheckerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FallbackPackabilityCheckerAdapter::class)]
#[UsesClass(Box::class)]
#[UsesClass(Product::class)]
final class FallbackPackabilityCheckerAdapterTest extends TestCase
{
    public function testProductFitsInBox(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [new Product(2.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertTrue($result);
    }

    public function testProductDoesNotFitByWidth(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [new Product(6.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertFalse($result);
    }

    public function testProductDoesNotFitByHeight(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [new Product(2.0, 6.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertFalse($result);
    }

    public function testProductDoesNotFitByLength(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [new Product(2.0, 2.0, 6.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertFalse($result);
    }

    public function testExceedsMaxWeight(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [
            new Product(2.0, 2.0, 2.0, 6.0),
            new Product(2.0, 2.0, 2.0, 5.0),
        ];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertFalse($result);
    }

    public function testMultipleProductsFit(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [
            new Product(2.0, 2.0, 2.0, 3.0),
            new Product(2.0, 2.0, 2.0, 3.0),
        ];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertTrue($result);
    }

    public function testTotalVolumeExceedsBoxVolume(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [
            new Product(2.0, 2.0, 2.0, 2.0),
            new Product(2.0, 2.0, 2.0, 2.0),
            new Product(2.0, 2.0, 2.0, 2.0),
            new Product(2.0, 2.0, 2.0, 2.0),
        ];
        $box = new Box(1, 3.0, 3.0, 3.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertFalse($result);
    }

    public function testProductCanBeRotated(): void
    {
        $checker = new FallbackPackabilityCheckerAdapter(new NullLogger());
        $products = [new Product(3.0, 1.0, 2.0, 5.0)];
        $box = new Box(1, 1.0, 2.0, 3.0, 10.0);

        $result = $checker->canPackIntoBox($products, $box);

        self::assertTrue($result);
    }
}
