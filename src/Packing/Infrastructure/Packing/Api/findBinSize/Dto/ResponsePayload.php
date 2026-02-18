<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing\Api\findBinSize\Dto;

use function array_key_exists;
use function array_values;
use function is_array;

final readonly class ResponsePayload
{
    /**
     * @param list<mixed> $errors
     */
    public function __construct(
        public mixed $status,
        public array $errors,
        public bool $hasBinsPacked,
        public mixed $binsPacked,
        public mixed $notPackedItems,
    ) {
    }

    /**
     * @param array<string, mixed> $responseNode
     */
    public static function fromResponseNode(array $responseNode): self
    {
        $errors = $responseNode['errors'] ?? null;

        return new self(
            status: $responseNode['status'] ?? null,
            errors: is_array($errors) ? array_values($errors) : [],
            hasBinsPacked: array_key_exists('bins_packed', $responseNode),
            binsPacked: $responseNode['bins_packed'] ?? null,
            notPackedItems: $responseNode['not_packed_items'] ?? null,
        );
    }
}
