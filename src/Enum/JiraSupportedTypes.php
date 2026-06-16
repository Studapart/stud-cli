<?php

declare(strict_types=1);

namespace App\Enum;

enum JiraSupportedTypes: string
{
    case Bug = 'bug';
    case Story = 'story';
    case Epic = 'epic';
    case Task = 'task';
    case SubTask = 'sub-task';

    public static function tryFromName(string $issueType): ?self
    {
        return self::tryFrom(strtolower($issueType));
    }
}
