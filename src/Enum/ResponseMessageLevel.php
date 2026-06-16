<?php

declare(strict_types=1);

namespace App\Enum;

enum ResponseMessageLevel: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
}
