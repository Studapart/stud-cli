<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\IssueTrackerResolutionException;

/**
 * Issue-tracker vendor slug (Jira, Linear) plus project-level Auto resolution mode.
 *
 * YAML keys stay `workItemProvider` / `WORK_ITEM_PROVIDERS` for backward compatibility.
 */
enum IssueTrackerProvider: string
{
    case Jira = 'jira';
    case Linear = 'linear';
    case Auto = 'auto';

    public static function tryFromNormalized(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * @return list<string>
     */
    public static function vendorValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::vendors());
    }

    /**
     * @return list<self>
     */
    public static function vendors(): array
    {
        return [self::Jira, self::Linear];
    }

    /**
     * Allowed values for project `workItemProvider` YAML / init prompts.
     *
     * @return list<string>
     */
    public static function projectConfigValues(): array
    {
        return [...self::vendorValues(), self::Auto->value];
    }

    public static function isProjectConfigValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::projectConfigValues(), true);
    }

    /**
     * Maps a resolved vendor slug to enum; rejects Auto and unknown values.
     */
    public static function fromResolved(string $provider): self
    {
        $resolved = self::tryFromNormalized($provider);
        if ($resolved === null || $resolved === self::Auto) {
            throw IssueTrackerResolutionException::unknownResolved($provider);
        }

        return $resolved;
    }

    public function isAuto(): bool
    {
        return $this === self::Auto;
    }

    public function isVendor(): bool
    {
        return $this === self::Jira || $this === self::Linear;
    }

    /**
     * @return 'jira'|'linear'
     */
    public function vendorSlug(): string
    {
        return match ($this) {
            self::Jira => self::Jira->value,
            self::Linear => self::Linear->value,
            self::Auto => throw new \LogicException('Auto is not a resolved vendor slug'),
        };
    }
}
