<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Product policy for Castor host behavior in shipped binaries.
 *
 * Packaged stud (PHAR / portable) always disables Castor AI-agent environment
 * guessing. stud agent JSON mode remains the explicit `--agent` flag only.
 */
final class CastorPackagingPolicy
{
    public const DISABLE_AGENT_DETECTION_ENV = 'CASTOR_DISABLE_AGENT_DETECTION';

    /**
     * Force-disable Castor agent detection when running a packaged binary.
     */
    public static function applyWhenPackaged(bool $packaged): void
    {
        if (! $packaged) {
            return;
        }

        putenv(self::DISABLE_AGENT_DETECTION_ENV . '=1');
        $_SERVER[self::DISABLE_AGENT_DETECTION_ENV] = '1';
        $_ENV[self::DISABLE_AGENT_DETECTION_ENV] = '1';
    }
}
