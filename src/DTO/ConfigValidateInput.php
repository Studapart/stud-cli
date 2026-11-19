<?php

declare(strict_types=1);

namespace App\DTO;

final class ConfigValidateInput
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        public readonly array $globalConfig,
        public readonly array $projectConfig,
        public readonly bool $inRepo,
        public readonly bool $skipJira,
        public readonly bool $skipLinear,
        public readonly bool $skipGit,
        public readonly ?string $detectedGitHost = null,
        public readonly ?MessageRef $projectConfigReadError = null,
    ) {
    }
}
