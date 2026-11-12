<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

/**
 * The tracker port for one create run. Either the port already chosen for this run, or the port
 * for the tracker the project lookup matched.
 */
class ItemCreatePortSource
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function __construct(
        private readonly IssueTrackerPort|IssueTrackerPortSupplier $resolver,
        private readonly array $globalConfig = [],
        private readonly array $projectConfig = [],
    ) {
    }

    public static function pinned(IssueTrackerPort $port): self
    {
        return new self($port);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public static function lookedUp(
        IssueTrackerPortSupplier $ports,
        array $globalConfig,
        array $projectConfig,
    ): self {
        return new self($ports, $globalConfig, $projectConfig);
    }

    public function pinnedPort(): ?IssueTrackerPort
    {
        return $this->resolver instanceof IssueTrackerPort ? $this->resolver : null;
    }

    public function portFor(string $provider): IssueTrackerPort|MessageRef
    {
        if ($this->resolver instanceof IssueTrackerPort) {
            return $this->resolver;
        }

        $resolution = $this->resolver->resolveForProvider($provider, $this->globalConfig, $this->projectConfig);

        return $resolution['ok'] ? $resolution['port'] : $resolution['error'];
    }
}
