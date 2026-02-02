<?php

declare(strict_types=1);

namespace App\Migrations\GlobalMigrations;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;

/**
 * Migration to convert old GIT_TOKEN/GIT_PROVIDER format to GITHUB_TOKEN/GITLAB_TOKEN format.
 * This migration replaces the old _migrate_git_config() function.
 */
class Migration202501150000001_GitTokenFormat extends AbstractMigration
{
    public function getId(): string
    {
        return '202501150000001';
    }

    public function getDescription(): string
    {
        return 'Migrate Git token configuration from GIT_TOKEN/GIT_PROVIDER to GITHUB_TOKEN/GITLAB_TOKEN format';
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
        // Check if migration is needed
        $hasOldToken = isset($config['GIT_TOKEN']) && is_string($config['GIT_TOKEN']) && trim($config['GIT_TOKEN']) !== '';
        $hasOldProvider = isset($config['GIT_PROVIDER']) && in_array($config['GIT_PROVIDER'], ['github', 'gitlab'], true);

        // No old token, nothing to migrate
        if (! $hasOldToken) {
            return $config;
        }

        $oldToken = trim($config['GIT_TOKEN']);

        // Case 1: Token + Provider -> Migrate automatically
        if ($hasOldProvider) {
            $provider = $config['GIT_PROVIDER'];
            $newTokenKey = $provider === 'github' ? 'GITHUB_TOKEN' : 'GITLAB_TOKEN';

            // Only migrate if new token key doesn't already exist
            if (! isset($config[$newTokenKey]) || empty(trim($config[$newTokenKey] ?? ''))) {
                $config[$newTokenKey] = $oldToken;
            }

            // Remove old keys
            unset($config['GIT_PROVIDER'], $config['GIT_TOKEN']);

            return $config;
        }

        // Case 2: Token but no Provider -> Prompt user
        // Note: This migration should not prompt interactively as it runs automatically.
        // If provider is missing, we'll skip migration and let user configure manually.
        // The old _migrate_git_config() function handled this case, but for automatic migrations,
        // we'll preserve the old token and let the user run config:init to fix it.

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        // Revert migration: convert GITHUB_TOKEN/GITLAB_TOKEN back to GIT_TOKEN/GIT_PROVIDER
        // This is a best-effort revert - we can't know which provider the token was for
        if (isset($config['GITHUB_TOKEN']) && ! isset($config['GIT_TOKEN'])) {
            $config['GIT_TOKEN'] = $config['GITHUB_TOKEN'];
            $config['GIT_PROVIDER'] = 'github';
            unset($config['GITHUB_TOKEN']);
        } elseif (isset($config['GITLAB_TOKEN']) && ! isset($config['GIT_TOKEN'])) {
            $config['GIT_TOKEN'] = $config['GITLAB_TOKEN'];
            $config['GIT_PROVIDER'] = 'gitlab';
            unset($config['GITLAB_TOKEN']);
        }

        return $config;
    }
}
