<?php

declare(strict_types=1);

namespace App\Handler;

/**
 * Constants for branch actions used in ItemStartHandler and related handlers.
 * These constants can be used by external functions to pass parameters.
 */
final class BranchAction
{
    public const SWITCH_LOCAL = 'switch_local';
    public const SWITCH_REMOTE = 'switch_remote';
    public const CREATE = 'create';

    private function __construct()
    {
        // This class only contains constants and should not be instantiated
    }
}
