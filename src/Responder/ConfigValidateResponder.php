<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\MessageRef;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfigValidateResponse;
use App\Service\ConfigReadinessEvaluator;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders config:validate result: rollups, integrations, and legacy flat aliases.
 */
class ConfigValidateResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger
    ) {
    }

    public function respond(
        SymfonyStyle $io,
        ConfigValidateResponse $response,
        OutputFormat $format = OutputFormat::Cli,
        bool $compact = true,
    ): ?AgentJsonResponse {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response, $compact);
        }

        if (! $response->isSuccess() && $response->getError() !== null && $response->integrations === []) {
            $message = $this->helper->translator->trans($response->getError());
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $message));

            return null;
        }

        $this->helper->initSection($this->logger, 'config.validate.section_title');

        $globalLabel = $this->helper->translator->trans('config.validate.label_global_rollup');
        $projectLabel = $this->helper->translator->trans('config.validate.label_project_rollup');
        $globalValue = $this->formatRollup($response->globalRollup);
        $projectValue = $response->projectRollup === ConfigReadinessEvaluator::ROLLUP_NOT_APPLICABLE
            ? $this->helper->translator->trans('config.validate.rollup_not_applicable')
            : $this->formatRollup($response->projectRollup);

        $this->logger->definitionList(
            Logger::VERBOSITY_NORMAL,
            [$globalLabel => $globalValue],
            [$projectLabel => $projectValue],
        );

        $integrationRows = [];
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
            $integrationRows[] = [$label => $value];
        }

        if ($integrationRows !== []) {
            $this->logger->definitionList(Logger::VERBOSITY_NORMAL, ...$integrationRows);
        }

        foreach ($response->getNotices() as $notice) {
            $message = $notice->message;
            $text = $message instanceof MessageRef
                ? $this->helper->translator->trans($message->key, $message->parameters)
                : (string) $message;
            $this->logger->note(Logger::VERBOSITY_NORMAL, $text);
        }

        foreach ($response->getWarnings() as $warning) {
            $message = $warning->message;
            $text = $message instanceof MessageRef
                ? $this->helper->translator->trans($message->key, $message->parameters)
                : (string) $message;
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $text);
        }

        return null;
    }

    protected function respondJson(ConfigValidateResponse $response, bool $compact): AgentJsonResponse
    {
        $diagnostics = $response->diagnosticsPayload(new MessageRenderer($this->helper->translator, true));
        $fullData = $this->buildFullData($response);

        if (! $response->isSuccess()) {
            $error = $response->getError() ?? 'Validation failed';
            $data = $compact
                ? $this->compactFailureData($response)
                : $fullData;

            return new AgentJsonResponse(
                false,
                data: $data,
                error: $this->helper->translator->transForAgentText($error),
                diagnostics: $diagnostics,
            );
        }

        if ($response->globalRollup === ConfigReadinessEvaluator::ROLLUP_PARTIAL) {
            if ($compact) {
                return new AgentJsonResponse(
                    true,
                    data: ['rollups' => $fullData['rollups']],
                    diagnostics: $diagnostics,
                );
            }

            return new AgentJsonResponse(true, data: $fullData, diagnostics: $diagnostics);
        }

        if ($compact) {
            return AgentJsonResponse::successWithoutData($diagnostics);
        }

        return new AgentJsonResponse(true, data: $fullData, diagnostics: $diagnostics);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildFullData(ConfigValidateResponse $response): array
    {
        return [
            'globalRollup' => $response->globalRollup,
            'projectRollup' => $response->projectRollup,
            'rollups' => [
                'global' => $response->globalRollup,
                'project' => $response->projectRollup,
            ],
            'integrations' => $this->integrationsForAgent($response->integrations),
            'jiraStatus' => $response->jiraStatus,
            'jiraMessage' => $this->formatMessageForAgent($response->jiraMessage),
            'gitStatus' => $response->gitStatus,
            'gitMessage' => $this->formatMessageForAgent($response->gitMessage),
            'linearStatus' => $response->linearStatus,
            'linearMessage' => $this->formatMessageForAgent($response->linearMessage),
            'gitProvider' => $response->gitProvider,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compactFailureData(ConfigValidateResponse $response): array
    {
        return [
            'globalRollup' => $response->globalRollup,
            'projectRollup' => $response->projectRollup,
            'rollups' => [
                'global' => $response->globalRollup,
                'project' => $response->projectRollup,
            ],
            'integrations' => $this->integrationsForAgent($response->integrations),
        ];
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     *
     * @return array<string, array{status: string, message: string|null}>
     */
    protected function integrationsForAgent(array $integrations): array
    {
        $out = [];
        foreach ($integrations as $key => $ping) {
            $out[$key] = [
                'status' => $ping->status,
                'message' => $this->formatMessageForAgent($ping->message),
            ];
        }

        return $out;
    }

    protected function formatMessageForAgent(MessageRef|string|null $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if ($message instanceof MessageRef) {
            return $this->helper->translator->transForAgentText($message->key, $message->parameters);
        }

        return $message;
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
    private function legacyCliIntegrationKeys(ConfigValidateResponse $response): array
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
        $detail = $this->formatComponentMessage($message);

        return $detail !== null && $detail !== ''
            ? $failLabel . ' (' . $detail . ')'
            : $failLabel;
    }

    protected function formatComponentMessage(MessageRef|string|null $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if ($message instanceof MessageRef) {
            return $this->helper->translator->trans($message->key, $message->parameters);
        }

        return $message;
    }
}
