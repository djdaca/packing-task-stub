<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Packing\Application\Port\BoxCatalogPort;
use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Application\UseCase\PackProductsHandler;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;

use function array_map;
use function hash;
use function json_encode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PackProductsHandler::class)]
#[UsesClass(Box::class)]
#[UsesClass(Product::class)]
final class PackProductsHandlerTest extends TestCase
{
    public function testSelectsSmallestPackableBoxAndCachesResult(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 1.0)];
        $small = new Box(1, 1.0, 1.0, 1.0, 10.0);
        $medium = new Box(2, 3.0, 3.0, 3.0, 10.0);
        $large = new Box(3, 10.0, 10.0, 10.0, 10.0);

        $catalog = new InMemoryBoxCatalog([$large, $small, $medium]);
        $cache = new InMemoryCache();
        $checker = new CallbackChecker(static function (array $inputProducts, Box $box): bool {
            return $box->getId() === 2;
        });

        $handler = new PackProductsHandler($catalog, $checker, $cache, new NullLogger());
        $selected = $handler->handle($products);

        self::assertNotNull($selected);
        self::assertSame(2, $selected->getId());
    }

    public function testUsesCacheAndSkipsCheckerWhenCached(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 1.0)];
        $box = new Box(9, 3.0, 3.0, 3.0, 10.0);

        $catalog = new InMemoryBoxCatalog([$box]);
        $cache = new InMemoryCache();
        $cache->storage[$cache->buildKey($products)] = 9;
        $checker = new CallbackChecker(static fn (): bool => true);

        $handler = new PackProductsHandler($catalog, $checker, $cache, new NullLogger());
        $selected = $handler->handle($products);

        self::assertNotNull($selected);
        self::assertSame(9, $selected->getId());
    }

    public function testReturnsNullWhenNoBoxIsPackable(): void
    {
        $products = [new Product(2.0, 2.0, 2.0, 1.0)];
        $catalog = new InMemoryBoxCatalog([new Box(1, 1.0, 1.0, 1.0, 10.0)]);
        $cache = new InMemoryCache();
        $checker = new CallbackChecker(static fn (): bool => false);

        $handler = new PackProductsHandler($catalog, $checker, $cache, new NullLogger());
        $selected = $handler->handle($products);

        self::assertNull($selected);
    }
}

final class InMemoryBoxCatalog implements BoxCatalogPort
{
    /**
     * @param list<Box> $boxes
     */
    public function __construct(private array $boxes)
    {
    }

    /**
     * @return list<Box>
     */
    public function getAllBoxes(): array
    {
        return $this->boxes;
    }
}

final class InMemoryCache implements PackingCachePort
{
    /** @var array<string, int|null> */
    public array $storage = [];

    public function getSelectedBox(array $products): ?int
    {
        $key = $this->buildKey($products);

        return $this->storage[$key] ?? null;
    }

    public function storeSelectedBox(array $products, ?int $selectedBoxId): void
    {
        $this->storage[$this->buildKey($products)] = $selectedBoxId;
    }

    /**
     * @param list<Product> $products
     */
    public function buildKey(array $products): string
    {
        return hash('sha256', json_encode([
            'products' => array_map(static fn (Product $product): array => $product->toArray(), $products),
            'version' => 3,
        ], JSON_THROW_ON_ERROR));
    }
}

final class CallbackChecker implements PackabilityCheckerPort
{
    /** @var \Closure(list<Product>, Box): bool */
    private \Closure $callback;
    public int $calls = 0;

    /**
     * @param \Closure(list<Product>, Box): bool $callback
     */
    public function __construct(\Closure $callback)
    {
        $this->callback = $callback;
    }

    public function canPackIntoBox(array $products, Box $box): bool
    {
        $this->calls++;

        return ($this->callback)($products, $box);
    }
}
