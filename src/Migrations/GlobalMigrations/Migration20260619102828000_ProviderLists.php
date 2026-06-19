<?php

declare(strict_types=1);

namespace App\Migrations\GlobalMigrations;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;
use App\Service\GlobalConfigProviderResolver;

/**
 * Backfill GIT_PROVIDERS and WORK_ITEM_PROVIDERS from legacy credential keys.
 */
class Migration20260619102828000_ProviderLists extends AbstractMigration
{
    public function getId(): string
    {
        return '20260619102828000';
    }

    public function getDescription(): string
    {
        return 'Backfill GIT_PROVIDERS and WORK_ITEM_PROVIDERS from stored credential keys';
    }

    public function getScope(): MigrationScope
    {
        return MigrationScope::GLOBAL;
    }

    public function isPrerequisite(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function up(array $config): array
    {
        $resolver = new GlobalConfigProviderResolver();

        if (! $this->hasNonEmptyProviderList($config, 'GIT_PROVIDERS')) {
            $gitProviders = $resolver->inferGitProvidersFromLegacy($config);
            if ($gitProviders !== []) {
                $config['GIT_PROVIDERS'] = $gitProviders;
            }
        }

        if (! $this->hasNonEmptyProviderList($config, 'WORK_ITEM_PROVIDERS')) {
            $workItemProviders = $resolver->inferWorkItemProvidersFromCredentials($config);
            if ($workItemProviders !== []) {
                $config['WORK_ITEM_PROVIDERS'] = $workItemProviders;
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        unset($config['GIT_PROVIDERS'], $config['WORK_ITEM_PROVIDERS']);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function hasNonEmptyProviderList(array $config, string $key): bool
    {
        if (! isset($config[$key]) || ! is_array($config[$key])) {
            return false;
        }

        foreach ($config[$key] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }
}
