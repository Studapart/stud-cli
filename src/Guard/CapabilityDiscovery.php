<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitHosting\GithubAware;
use App\Guard\Capability\GitHosting\GitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Guard\Capability\ProjectBaseBranchAware;

/**
 * Discovers capability marker interfaces implemented by a handler class.
 */
class CapabilityDiscovery
{
    /** @var list<class-string> */
    private const MARKER_INTERFACES = [
        JiraAware::class,
        LinearAware::class,
        GithubAware::class,
        GitlabAware::class,
        GitRepositoryAware::class,
        ProjectBaseBranchAware::class,
        ConfluenceAware::class,
    ];

    /**
     * @param class-string $handlerClass
     */
    public static function fromClass(string $handlerClass): CapabilitySet
    {
        $implemented = class_implements($handlerClass) ?: [];
        $capabilities = [];

        foreach (self::MARKER_INTERFACES as $marker) {
            if (isset($implemented[$marker])) {
                $capabilities[] = $marker;
            }
        }

        return CapabilitySet::fromList($capabilities);
    }
}
