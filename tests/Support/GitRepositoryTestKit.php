<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\FileSystem;
use App\Service\GitProjectConfigService;
use App\Service\GitRebaseAutosquashService;
use App\Service\GitRemoteUrlParser;
use App\Service\GitRepository;
use App\Service\ProcessFactory;

final class GitRepositoryTestKit
{
    public static function create(ProcessFactory $processFactory, FileSystem $fileSystem): GitRepository
    {
        $remoteUrlParser = new GitRemoteUrlParser($processFactory);
        $projectConfigService = new GitProjectConfigService($processFactory, $fileSystem, $remoteUrlParser);
        $rebaseAutosquashService = new GitRebaseAutosquashService($processFactory, $fileSystem);

        return new GitRepository($processFactory, $projectConfigService, $remoteUrlParser, $rebaseAutosquashService);
    }
}
