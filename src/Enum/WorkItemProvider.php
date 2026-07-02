<?php

declare(strict_types=1);

namespace App\Enum;

enum WorkItemProvider: string
{
    case Jira = 'jira';
    case Linear = 'linear';

    /** Project-level `workItemProvider` value: defer to global/credential auto-resolution. */
    public const PROJECT_AUTO = 'auto';

    public static function tryFromNormalized(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Allowed values for project `workItemProvider` YAML / init prompts.
     *
     * @return list<string>
     */
    public static function projectConfigValues(): array
    {
        return [...self::values(), self::PROJECT_AUTO];
    }

    public static function isProjectConfigValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::projectConfigValues(), true);
    }
}
