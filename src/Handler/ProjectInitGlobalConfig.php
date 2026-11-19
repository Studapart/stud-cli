<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\GlobalStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Service\FileSystem;
use App\Service\GlobalConfigProviderResolver;

final class ProjectInitGlobalConfig
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly string $path,
        public readonly GlobalConfigProviderResolver $providers,
    ) {
    }

    /**
     * @return list<string>
     */
    public function readIssueTrackerProviders(): array
    {
        if (! $this->fileSystem->fileExists($this->path)) {
            return [IssueTrackerProvider::Jira->value];
        }

        try {
            $config = $this->fileSystem->parseFile($this->path);
        } catch (\Throwable) {
            return [IssueTrackerProvider::Jira->value];
        }

        $providers = GlobalStudConfigKeys::readIssueTrackerProvidersList($config);
        if ($providers !== null) {
            return $this->providers->normalizeIssueTrackerProviders($providers);
        }

        return $this->providers->inferDefaultIssueTrackerProviders($config);
    }
}
