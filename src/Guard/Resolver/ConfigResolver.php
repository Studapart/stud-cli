<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

/**
 * Supplies global and project config snapshots for command readiness checks.
 *
 * Merge precedence and fallbacks (project over global, legacy keys) are deferred
 * to Phase C; the listener currently loads YAML before the guard runs.
 */
class ConfigResolver
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return array{global: array<string, mixed>, project: array<string, mixed>|null}
     */
    public function resolve(array $globalConfig, ?array $projectConfig): array
    {
        return [
            'global' => $globalConfig,
            'project' => $projectConfig,
        ];
    }
}
