<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Guards {@see \ReflectionMethod::setAccessible()} / {@see \ReflectionProperty::setAccessible()}
 * so public members skip the call (deprecated/no-op noise on PHP 8.1+).
 */
final class ReflectionAccessor
{
    public static function ensureAccessible(\ReflectionMethod|\ReflectionProperty $member): void
    {
        if ($member->isPublic()) {
            return;
        }

        $member->setAccessible(true);
    }
}
