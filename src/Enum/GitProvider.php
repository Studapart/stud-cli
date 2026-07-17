<?php

declare(strict_types=1);

namespace App\Enum;

enum GitProvider: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';
}
