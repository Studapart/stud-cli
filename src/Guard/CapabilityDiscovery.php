<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitProviderGithubAware;
use App\Guard\Capability\GitProviderGitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;

/**
 * Discovers capability marker interfaces implemented by a handler class.
 */
class CapabilityDiscovery
{
    /** @var list<class-string> */
    private const MARKER_INTERFACES = [
        WorkItemJiraAware::class,
        WorkItemLinearAware::class,
        GitProviderGithubAware::class,
        GitProviderGitlabAware::class,
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
