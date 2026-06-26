<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Jira status category display names used in JQL (protocol vocabulary, not user-facing copy).
 */
enum JiraStatusCategory: string
{
    case ToDo = 'To Do';
    case InProgress = 'In Progress';
    case Done = 'Done';

    /**
     * @return list<string>
     */
    public static function activeListJqlLiterals(): array
    {
        return [self::ToDo->value, self::InProgress->value];
    }

    public static function activeListJqlClause(): string
    {
        $quoted = array_map(static fn (string $value): string => "'{$value}'", self::activeListJqlLiterals());

        return 'statusCategory in (' . implode(', ', $quoted) . ')';
    }

    public static function notDoneJqlClause(): string
    {
        return 'statusCategory != ' . self::Done->value;
    }
}
