<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\ConfigValidatePlan;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
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
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     * @param list<MessageRef|string>                       $notices
     */
    public function evaluate(
        array $integrations,
        ConfigValidatePlan $plan,
        array $notices = [],
    ): ConfigReadinessEvaluation {
        $globalRollup = $this->globalRollup($integrations);
        $projectRollup = $this->projectRollup($integrations, $plan, $notices);
        $success = $this->overallSuccess($globalRollup, $projectRollup, $plan);

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

        return $oks === 0 ? self::ROLLUP_FAIL : self::ROLLUP_PARTIAL;
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     * @param list<MessageRef|string>                       $notices
     */
    private function projectRollup(array $integrations, ConfigValidatePlan $plan, array &$notices): string
    {
        if (! $plan->inRepo || ! $plan->hasProjectConfig) {
            return self::ROLLUP_NOT_APPLICABLE;
        }

        if (! $this->projectGitOk($integrations, $plan) || ! $this->selectedTrackerOk($integrations, $plan)) {
            return self::ROLLUP_FAIL;
        }

        $this->collectNonSelectedTrackerNotices($integrations, $plan, $notices);

        return self::ROLLUP_OK;
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function projectGitOk(array $integrations, ConfigValidatePlan $plan): bool
    {
        if (! $plan->gitProbeRequired()) {
            return true;
        }

        $gitKey = $plan->projectGit?->integrationKey();
        $gitPing = $gitKey !== null ? ($integrations[$gitKey] ?? null) : null;

        return $gitPing !== null && $gitPing->status === ConfigValidateResponse::STATUS_OK;
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function selectedTrackerOk(array $integrations, ConfigValidatePlan $plan): bool
    {
        $selected = $plan->selectedTracker;
        if ($selected === null || ! $selected->isVendor()) {
            return false;
        }

        if (! $plan->probesTracker($selected)) {
            return $plan->waivesSelectedTrackerProbe($selected);
        }

        $ping = $integrations[$selected->value] ?? null;

        return $ping !== null && $ping->status === ConfigValidateResponse::STATUS_OK;
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     * @param list<MessageRef|string>                       $notices
     */
    private function collectNonSelectedTrackerNotices(
        array $integrations,
        ConfigValidatePlan $plan,
        array &$notices,
    ): void {
        foreach (IssueTrackerProvider::vendors() as $provider) {
            if ($provider === $plan->selectedTracker) {
                continue;
            }
            $other = $integrations[$provider->value] ?? null;
            if ($other !== null && $other->status === ConfigValidateResponse::STATUS_FAIL) {
                $notices[] = $this->noticeForTracker($provider);
            }
        }
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

    private function noticeForTracker(IssueTrackerProvider $tracker): MessageRef
    {
        $key = $tracker === IssueTrackerProvider::Linear
            ? 'config.validate.notice_linear_non_blocking'
            : 'config.validate.notice_jira_non_blocking';

        return MessageRef::key($key);
    }

    private function overallSuccess(string $globalRollup, string $projectRollup, ConfigValidatePlan $plan): bool
    {
        if ($plan->inRepo && $plan->hasProjectConfig) {
            return $projectRollup !== self::ROLLUP_FAIL;
        }

        return $globalRollup !== self::ROLLUP_FAIL;
    }
}
