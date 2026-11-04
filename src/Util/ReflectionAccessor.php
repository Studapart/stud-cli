<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Kept so callers can mark a reflected member before invoke or getValue.
 * setAccessible() has had no effect since PHP 8.1 and is deprecated in PHP 8.5.
 */
final class ReflectionAccessor
{
    public static function ensureAccessible(\ReflectionMethod|\ReflectionProperty $member): void
    {
        unset($member);
    }
}
