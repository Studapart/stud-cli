<?php

declare(strict_types=1);

namespace App\Enum;

enum OutputFormat: string
{
    case Cli = 'cli';
    case Json = 'json';
}
