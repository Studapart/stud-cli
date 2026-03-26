<?php

declare(strict_types=1);

namespace App\Enum;

enum BranchAutoCleanDecision: string
{
    case Yes = 'yes';
    case No = 'no';
    case Manual = 'manual';
}
