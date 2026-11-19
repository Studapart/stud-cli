<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\GitRepository;

final class CommandContextInput
{
    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        public readonly array $globalConfig,
        public readonly ?array $projectConfig,
        public readonly bool $hasGitRepository,
        public readonly ?string $resolvedGitProvider,
        public readonly string $commandName,
        public readonly ?GitRepository $gitRepository = null,
    ) {
    }
}
