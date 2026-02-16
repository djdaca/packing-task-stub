<?php

declare(strict_types=1);

namespace App\Shared;

use function is_numeric;
use function is_string;

final class Env
{
    public static function getString(string $key, string $default = ''): string
    {
        $val = $_ENV[$key] ?? $default;

        return is_string($val) ? $val : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $val = $_ENV[$key] ?? $default;

        return is_numeric($val) ? (int) $val : $default;
    }
}
