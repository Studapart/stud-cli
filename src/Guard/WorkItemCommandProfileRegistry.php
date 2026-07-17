<?php

declare(strict_types=1);

namespace App\Guard;

use App\Enum\WorkItemCommandProfile;

/**
 * Maps canonical work-item commands to resolution profiles (SCI-197).
 */
final class WorkItemCommandProfileRegistry
{
    /**
     * @var array<string, WorkItemCommandProfile>
     */
    private const PROFILES = [
        'items:list' => WorkItemCommandProfile::DualAggregate,
        'filters:list' => WorkItemCommandProfile::DualAggregate,
        'projects:list' => WorkItemCommandProfile::DualAggregate,
        'filters:show' => WorkItemCommandProfile::FilterByName,
        'items:show' => WorkItemCommandProfile::KeySingle,
        'items:update' => WorkItemCommandProfile::KeySingle,
        'items:start' => WorkItemCommandProfile::KeySingle,
        'items:takeover' => WorkItemCommandProfile::KeySingle,
        'items:download' => WorkItemCommandProfile::KeySingle,
        'items:upload' => WorkItemCommandProfile::KeySingle,
        'items:transition' => WorkItemCommandProfile::KeyOrBranch,
        'commit' => WorkItemCommandProfile::KeyOrBranch,
        'push' => WorkItemCommandProfile::KeyOrBranch,
        'submit' => WorkItemCommandProfile::KeyOrBranch,
        'status' => WorkItemCommandProfile::KeyOrBranch,
        'branch:rename' => WorkItemCommandProfile::KeyOrBranch,
        'items:create' => WorkItemCommandProfile::ScopeDiscovery,
        'projects:workflow' => WorkItemCommandProfile::ScopeDiscovery,
        'projects:labels' => WorkItemCommandProfile::LinearOnly,
        'items:search' => WorkItemCommandProfile::SearchSingle,
    ];

    public static function forCommand(string $commandName): ?WorkItemCommandProfile
    {
        $canonical = CommandHandlerRegistry::canonicalName($commandName);

        return self::PROFILES[$canonical] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function inScopeCommandNames(): array
    {
        return array_keys(self::PROFILES);
    }
}
