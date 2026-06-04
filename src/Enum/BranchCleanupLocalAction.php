<?php

declare(strict_types=1);

namespace App\Enum;

enum BranchCleanupLocalAction: string
{
    case Skip = 'skip';
    case SafeDelete = 'safe_delete';
    case ForceDelete = 'force_delete';
    case Manual = 'manual';
}
