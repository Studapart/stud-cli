<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\MessageRef;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
use App\Response\ConfigValidateResponse;
use App\Service\ConfigReadinessEvaluator;
use App\Service\ResponderHelper;

/**
 * Builds the translated CLI rows and message texts for config:validate.
 */
class ConfigValidateCliView
{
    public function __construct(
        private readonly ResponderHelper $helper,
    ) {
    }

    /**
     * @return list<array<string, string>>
     */
    public function rollupRows(ConfigValidateResponse $response): array
    {
        $globalLabel = $this->helper->translator->trans('config.validate.label_global_rollup');
        $projectLabel = $this->helper->translator->trans('config.validate.label_project_rollup');
        $projectValue = $response->projectRollup === ConfigReadinessEvaluator::ROLLUP_NOT_APPLICABLE
            ? $this->helper->translator->trans('config.validate.rollup_not_applicable')
            : $this->formatRollup($response->projectRollup);

        return [
            [$globalLabel => $this->formatRollup($response->globalRollup)],
            [$projectLabel => $projectValue],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function integrationRows(ConfigValidateResponse $response): array
    {
        $rows = [];
        foreach ($this->cliIntegrationKeys($response) as $key) {
            $label = $this->integrationLabel($key);
            $value = $this->formatStatus(
                $this->integrationStatus($response, $key),
                $this->integrationMessage($response, $key),
            );
            if ($this->helper->colorHelper !== null) {
                $label = $this->helper->colorHelper->format('definition_key', $label);
                $value = $this->helper->colorHelper->format('definition_value', $value);
            }
            $rows[] = [$label => $value];
        }

        return $rows;
    }

    public function messageText(MessageRef|string $message): string
    {
        return $message instanceof MessageRef
            ? $this->helper->translator->trans($message->key, $message->parameters)
            : $message;
    }

    /**
     * @return list<string>
     */
    protected function cliIntegrationKeys(ConfigValidateResponse $response): array
    {
        if ($response->integrations !== []) {
            $order = [
                IssueTrackerProvider::Jira->value,
                GitProvider::Github->integrationKey(),
                GitProvider::Gitlab->integrationKey(),
                IssueTrackerProvider::Linear->value,
            ];
            $keys = [];
            foreach ($order as $key) {
                if (isset($response->integrations[$key])) {
                    $keys[] = $key;
                }
            }

            return $keys;
        }

        return $this->legacyCliIntegrationKeys($response);
    }

    /**
     * @return list<string>
     */
    protected function legacyCliIntegrationKeys(ConfigValidateResponse $response): array
    {
        $keys = [IssueTrackerProvider::Jira->value];
        $gitKey = GitProvider::tryFromProjectValue($response->gitProvider)?->integrationKey();
        if ($gitKey !== null) {
            $keys[] = $gitKey;
        } elseif ($response->gitStatus !== ConfigValidateResponse::STATUS_SKIPPED) {
            $keys[] = GitProvider::Github->integrationKey();
        }
        $keys[] = IssueTrackerProvider::Linear->value;

        return $keys;
    }

    protected function integrationStatus(ConfigValidateResponse $response, string $key): string
    {
        $ping = $response->integrations[$key] ?? null;
        if ($ping !== null) {
            return $ping->status;
        }

        return match ($key) {
            IssueTrackerProvider::Jira->value => $response->jiraStatus,
            IssueTrackerProvider::Linear->value => $response->linearStatus,
            GitProvider::Github->integrationKey(), GitProvider::Gitlab->integrationKey() => $response->gitStatus,
            default => ConfigValidateResponse::STATUS_SKIPPED,
        };
    }

    protected function integrationMessage(ConfigValidateResponse $response, string $key): MessageRef|string|null
    {
        $ping = $response->integrations[$key] ?? null;
        if ($ping !== null) {
            return $ping->message;
        }

        return match ($key) {
            IssueTrackerProvider::Jira->value => $response->jiraMessage,
            IssueTrackerProvider::Linear->value => $response->linearMessage,
            GitProvider::Github->integrationKey(), GitProvider::Gitlab->integrationKey() => $response->gitMessage,
            default => null,
        };
    }

    protected function integrationLabel(string $key): string
    {
        return match ($key) {
            IssueTrackerProvider::Jira->value => $this->helper->translator->trans('config.validate.label_jira'),
            IssueTrackerProvider::Linear->value => $this->helper->translator->trans('config.validate.label_linear'),
            GitProvider::Github->integrationKey() => $this->helper->translator->trans('config.validate.label_github'),
            GitProvider::Gitlab->integrationKey() => $this->helper->translator->trans('config.validate.label_gitlab'),
            default => $key,
        };
    }

    protected function formatRollup(string $rollup): string
    {
        return match ($rollup) {
            ConfigReadinessEvaluator::ROLLUP_OK => $this->helper->translator->trans('config.validate.rollup_ok'),
            ConfigReadinessEvaluator::ROLLUP_PARTIAL => $this->helper->translator->trans('config.validate.rollup_partial'),
            ConfigReadinessEvaluator::ROLLUP_FAIL => $this->helper->translator->trans('config.validate.rollup_fail'),
            default => $rollup,
        };
    }

    protected function formatStatus(string $status, MessageRef|string|null $message): string
    {
        if ($status === ConfigValidateResponse::STATUS_OK) {
            return $this->helper->translator->trans('config.validate.status_ok');
        }

        if ($status === ConfigValidateResponse::STATUS_SKIPPED) {
            return $this->helper->translator->trans('config.validate.status_skipped');
        }

        $failLabel = $this->helper->translator->trans('config.validate.status_fail');
        $detail = $message === null ? null : $this->messageText($message);

        return $detail !== null && $detail !== ''
            ? $failLabel . ' (' . $detail . ')'
            : $failLabel;
    }
}
