<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\ResilientPackabilityChecker;
use App\Packing\Infrastructure\Packing\ThirdPartyPackingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ResilientPackabilityChecker::class)]
#[UsesClass(Box::class)]
#[UsesClass(Product::class)]
final class ResilientPackabilityCheckerTest extends TestCase
{
    public function testUsesPrimaryWhenSuccessful(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $primary = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function canPackIntoBox(array $products, Box $box): bool
            {
                $this->calls++;

                return true;
            }
        };

        $fallback = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function canPackIntoBox(array $products, Box $box): bool
            {
                $this->calls++;

                return false;
            }
        };

        $checker = new ResilientPackabilityChecker($primary, $fallback, new NullLogger());
        $result = $checker->canPackIntoBox($products, $box);

        self::assertTrue($result);
        self::assertSame(1, $primary->calls);
        self::assertSame(0, $fallback->calls);
    }

    public function testUsesFallbackWhenPrimaryFails(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $primary = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function canPackIntoBox(array $products, Box $box): bool
            {
                $this->calls++;

                throw new ThirdPartyPackingException('API failure');
            }
        };

        $fallback = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function canPackIntoBox(array $products, Box $box): bool
            {
                $this->calls++;

                return true;
            }
        };

        $checker = new ResilientPackabilityChecker($primary, $fallback, new NullLogger());
        $result = $checker->canPackIntoBox($products, $box);

        self::assertTrue($result);
        self::assertSame(1, $primary->calls);
        self::assertSame(1, $fallback->calls);
    }

    public function testReturnsFallbackResultWhenPrimaryThrows(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $primary = new class () implements PackabilityCheckerPort {
            public function canPackIntoBox(array $products, Box $box): bool
            {
                throw new ThirdPartyPackingException('Network error');
            }
        };

        $fallback = new class () implements PackabilityCheckerPort {
            public function canPackIntoBox(array $products, Box $box): bool
            {
                return false;
            }
        };

        $checker = new ResilientPackabilityChecker($primary, $fallback, new NullLogger());
        $result = $checker->canPackIntoBox($products, $box);

        self::assertFalse($result);
    }
}
