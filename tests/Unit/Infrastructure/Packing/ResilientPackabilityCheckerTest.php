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

            public function findFirstPackableBox(array $products, array $boxes): Box|null
            {
                $this->calls++;

                return $boxes[0] ?? null;
            }
        };

        $fallback = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function findFirstPackableBox(array $products, array $boxes): Box|null
            {
                $this->calls++;

                return null;
            }
        };

        $checker = new ResilientPackabilityChecker($primary, $fallback, new NullLogger());
        $result = $checker->findFirstPackableBox($products, [$box]);

        self::assertSame(1, $result?->getId());
        self::assertSame(1, $primary->calls);
        self::assertSame(0, $fallback->calls);
    }

    public function testUsesFallbackWhenPrimaryFails(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $primary = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function findFirstPackableBox(array $products, array $boxes): Box|null
            {
                $this->calls++;

                throw new ThirdPartyPackingException('API failure');
            }
        };

        $fallback = new class () implements PackabilityCheckerPort {
            public int $calls = 0;

            public function findFirstPackableBox(array $products, array $boxes): Box|null
            {
                $this->calls++;

                return $boxes[0] ?? null;
            }
        };

        $checker = new ResilientPackabilityChecker($primary, $fallback, new NullLogger());
        $result = $checker->findFirstPackableBox($products, [$box]);

        self::assertSame(1, $result?->getId());
        self::assertSame(1, $primary->calls);
        self::assertSame(1, $fallback->calls);
    }

    public function testReturnsFallbackResultWhenPrimaryThrows(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 5.0)];
        $box = new Box(1, 5.0, 5.0, 5.0, 10.0);

        $primary = new class () implements PackabilityCheckerPort {
            public function findFirstPackableBox(array $products, array $boxes): Box|null
            {
                throw new ThirdPartyPackingException('Network error');
            }
        };

        $fallback = new class () implements PackabilityCheckerPort {
            public function findFirstPackableBox(array $products, array $boxes): Box|null
            {
                return null;
            }
        };

        $checker = new ResilientPackabilityChecker($primary, $fallback, new NullLogger());
        $result = $checker->findFirstPackableBox($products, [$box]);

        self::assertNull($result);
    }
}
