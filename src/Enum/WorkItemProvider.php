<?php

declare(strict_types=1);

namespace App\Enum;

enum WorkItemProvider: string
{
    case Jira = 'jira';
    case Linear = 'linear';
}
