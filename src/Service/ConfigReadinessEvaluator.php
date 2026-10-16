<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConfigValidateIntegrationPing;
use App\Guard\DTO\ProviderResolutionResult;
use App\Response\ConfigValidateResponse;

/**
 * Pure policy for config:validate global/project rollups and overall success (SCI-218).
 */
final class ConfigReadinessEvaluator
{
    public const ROLLUP_OK = 'ok';
    public const ROLLUP_PARTIAL = 'partial';
    public const ROLLUP_FAIL = 'fail';
    public const ROLLUP_NOT_APPLICABLE = 'not_applicable';

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations executed or skipped rows keyed jira|linear|github|gitlab
     * @param list<string>                                   $notices
     */
    public function evaluate(
        array $integrations,
        ProviderResolutionResult $resolution,
        bool $inRepo,
        bool $hasProjectConfig,
        ?string $projectGitProvider,
        array $notices = [],
    ): ConfigReadinessEvaluation {
        $globalRollup = $this->globalRollup($integrations);
        $projectRollup = $this->projectRollup(
            $integrations,
            $resolution,
            $inRepo,
            $hasProjectConfig,
            $projectGitProvider,
            $notices,
        );

        $success = $this->overallSuccess($globalRollup, $projectRollup, $inRepo, $hasProjectConfig);

        return new ConfigReadinessEvaluation($globalRollup, $projectRollup, $success, $notices);
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function globalRollup(array $integrations): string
    {
        $executed = $this->executedStatuses($integrations);
        if ($executed === []) {
            return self::ROLLUP_OK;
        }

        $oks = 0;
        $fails = 0;
        foreach ($executed as $status) {
            if ($status === ConfigValidateResponse::STATUS_OK) {
                ++$oks;
            } elseif ($status === ConfigValidateResponse::STATUS_FAIL) {
                ++$fails;
            }
        }

        if ($fails === 0) {
            return self::ROLLUP_OK;
        }

        if ($oks === 0) {
            return self::ROLLUP_FAIL;
        }

        return self::ROLLUP_PARTIAL;
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     * @param list<string>                                   $notices
     */
    private function projectRollup(
        array $integrations,
        ProviderResolutionResult $resolution,
        bool $inRepo,
        bool $hasProjectConfig,
        ?string $projectGitProvider,
        array &$notices,
    ): string {
        if (! $inRepo || ! $hasProjectConfig) {
            return self::ROLLUP_NOT_APPLICABLE;
        }

        $projectGitKey = $this->projectGitIntegrationKey($projectGitProvider);
        if ($projectGitKey !== null) {
            $gitPing = $integrations[$projectGitKey] ?? null;
            if ($gitPing !== null) {
                if ($gitPing->status === ConfigValidateResponse::STATUS_FAIL) {
                    return self::ROLLUP_FAIL;
                }
                if ($gitPing->status !== ConfigValidateResponse::STATUS_OK) {
                    return self::ROLLUP_FAIL;
                }
            }
        }

        $selected = $this->selectedProjectTracker($resolution);
        if ($selected === null) {
            return self::ROLLUP_FAIL;
        }

        $selectedPing = $integrations[$selected] ?? null;
        if ($selectedPing !== null) {
            if ($selectedPing->status === ConfigValidateResponse::STATUS_FAIL) {
                return self::ROLLUP_FAIL;
            }
            if ($selectedPing->status !== ConfigValidateResponse::STATUS_OK) {
                return self::ROLLUP_FAIL;
            }
        }

        foreach (['jira', 'linear'] as $tracker) {
            if ($tracker === $selected) {
                continue;
            }
            $other = $integrations[$tracker] ?? null;
            if ($other !== null && $other->status === ConfigValidateResponse::STATUS_FAIL) {
                $notices[] = $this->noticeForTracker($tracker, $other);
            }
        }

        return self::ROLLUP_OK;
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     *
     * @return list<string>
     */
    private function executedStatuses(array $integrations): array
    {
        $statuses = [];
        foreach ($integrations as $ping) {
            if ($ping->status === ConfigValidateResponse::STATUS_SKIPPED) {
                continue;
            }
            $statuses[] = $ping->status;
        }

        return $statuses;
    }

    private function projectGitIntegrationKey(?string $projectGitProvider): ?string
    {
        if ($projectGitProvider === 'github') {
            return 'github';
        }
        if ($projectGitProvider === 'gitlab') {
            return 'gitlab';
        }

        return null;
    }

    private function selectedProjectTracker(ProviderResolutionResult $resolution): ?string
    {
        if ($resolution->isBlocked() || $resolution->providers === []) {
            return null;
        }

        return $resolution->providers[0];
    }

    private function noticeForTracker(string $tracker, ConfigValidateIntegrationPing $ping): string
    {
        $label = $tracker === 'linear' ? 'Linear' : 'Jira';

        return $label . ' connectivity check failed (non-blocking for this project).';
    }

    private function overallSuccess(
        string $globalRollup,
        string $projectRollup,
        bool $inRepo,
        bool $hasProjectConfig,
    ): bool {
        if ($inRepo && $hasProjectConfig) {
            return $projectRollup !== self::ROLLUP_FAIL;
        }

        return $globalRollup !== self::ROLLUP_FAIL;
    }
}
