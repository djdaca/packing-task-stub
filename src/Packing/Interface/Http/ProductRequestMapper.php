<?php

declare(strict_types=1);

namespace App\Packing\Interface\Http;

use App\Packing\Domain\Exception\DomainValidationException;
use App\Packing\Domain\Model\Product;

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

final class ProductRequestMapper
{
    /**
     * @param array<string, mixed> $payload
     * @return list<Product>
     */
    public function map(array $payload): array
    {
        if (!isset($payload['products']) || !is_array($payload['products'])) {
            throw new InputValidationException('Field "products" is required and must be an array.');
        }

        if ($payload['products'] === []) {
            throw new InputValidationException('Field "products" must contain at least one product.');
        }

        $products = [];
        foreach ($payload['products'] as $index => $item) {
            $typedItem = $this->normalizeProductItem($item, $index);

            try {
                $products[] = new Product(
                    $this->extractNumber($typedItem, 'width', $index),
                    $this->extractNumber($typedItem, 'height', $index),
                    $this->extractNumber($typedItem, 'length', $index),
                    $this->extractNumber($typedItem, 'weight', $index),
                );
            } catch (DomainValidationException $exception) {
                throw new InputValidationException($exception->getMessage(), 0, $exception);
            }
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractNumber(array $item, string $field, int $index): float
    {
        if (!array_key_exists($field, $item)) {
            throw new InputValidationException(sprintf('Product at index %d is missing field "%s".', $index, $field));
        }

        if (!is_int($item[$field]) && !is_float($item[$field])) {
            throw new InputValidationException(sprintf('Product at index %d field "%s" must be a number.', $index, $field));
        }

        return (float) $item[$field];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProductItem(mixed $item, int $index): array
    {
        if (!is_array($item)) {
            throw new InputValidationException(sprintf('Product at index %d must be an object.', $index));
        }

        foreach ($item as $key => $_) {
            if (!is_string($key)) {
                throw new InputValidationException(sprintf('Product at index %d must be an object.', $index));
            }
        }

        /** @var array<string, mixed> $item */
        return $item;
    }
}
