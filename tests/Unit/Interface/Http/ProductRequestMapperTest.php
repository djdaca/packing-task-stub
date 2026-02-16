<?php

declare(strict_types=1);

namespace Tests\Unit\Interface\Http;

use App\Packing\Domain\Model\Product;
use App\Packing\Interface\Http\InputValidationException;
use App\Packing\Interface\Http\ProductRequestMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductRequestMapper::class)]
#[UsesClass(Product::class)]
#[UsesClass(InputValidationException::class)]
final class ProductRequestMapperTest extends TestCase
{
    public function testMapsValidSingleProduct(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 5.0,
                    'height' => 3.0,
                    'length' => 4.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $products = $mapper->map($payload);

        self::assertCount(1, $products);
        self::assertSame(5.0, $products[0]->getWidth());
        self::assertSame(3.0, $products[0]->getHeight());
        self::assertSame(4.0, $products[0]->getLength());
        self::assertSame(2.5, $products[0]->getWeight());
    }

    public function testMapsMultipleProducts(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 1.0,
                    'height' => 2.0,
                    'length' => 3.0,
                    'weight' => 4.0,
                ],
                [
                    'width' => 5.0,
                    'height' => 6.0,
                    'length' => 7.0,
                    'weight' => 8.0,
                ],
            ],
        ];

        $products = $mapper->map($payload);

        self::assertCount(2, $products);
        self::assertSame(1.0, $products[0]->getWidth());
        self::assertSame(5.0, $products[1]->getWidth());
    }

    public function testAcceptsIntegerValues(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 5,
                    'height' => 3,
                    'length' => 4,
                    'weight' => 2,
                ],
            ],
        ];

        $products = $mapper->map($payload);

        self::assertCount(1, $products);
        self::assertSame(5.0, $products[0]->getWidth());
    }

    public function testThrowsWhenProductsFieldMissing(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Field "products" is required and must be an array.');

        $mapper->map($payload);
    }

    public function testThrowsWhenProductsNotArray(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = ['products' => 'not-an-array'];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Field "products" is required and must be an array.');

        $mapper->map($payload);
    }

    public function testThrowsWhenProductsEmpty(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = ['products' => []];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Field "products" must contain at least one product.');

        $mapper->map($payload);
    }

    public function testThrowsWhenProductNotObject(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = ['products' => ['not-an-object']];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Product at index 0 must be an object.');

        $mapper->map($payload);
    }

    public function testThrowsWhenWidthMissing(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'height' => 3.0,
                    'length' => 4.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Product at index 0 is missing field "width".');

        $mapper->map($payload);
    }

    public function testThrowsWhenHeightMissing(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 5.0,
                    'length' => 4.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Product at index 0 is missing field "height".');

        $mapper->map($payload);
    }

    public function testThrowsWhenLengthMissing(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 5.0,
                    'height' => 3.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Product at index 0 is missing field "length".');

        $mapper->map($payload);
    }

    public function testThrowsWhenWeightMissing(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 5.0,
                    'height' => 3.0,
                    'length' => 4.0,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Product at index 0 is missing field "weight".');

        $mapper->map($payload);
    }

    public function testThrowsWhenWidthNotNumber(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 'not-a-number',
                    'height' => 3.0,
                    'length' => 4.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);
        $this->expectExceptionMessage('Product at index 0 field "width" must be a number.');

        $mapper->map($payload);
    }

    public function testThrowsWhenDimensionIsNegative(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => -5.0,
                    'height' => 3.0,
                    'length' => 4.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);

        $mapper->map($payload);
    }

    public function testThrowsWhenDimensionIsZero(): void
    {
        $mapper = new ProductRequestMapper();
        $payload = [
            'products' => [
                [
                    'width' => 0.0,
                    'height' => 3.0,
                    'length' => 4.0,
                    'weight' => 2.5,
                ],
            ],
        ];

        $this->expectException(InputValidationException::class);

        $mapper->map($payload);
    }
}
