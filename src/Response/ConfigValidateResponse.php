<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Service\ConfigReadinessEvaluation;
use App\Service\ConfigReadinessEvaluator;

/**
 * Response DTO for config:validate command.
 */
final class ConfigValidateResponse extends AbstractResponse
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAIL = 'fail';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     * @param list<ResponseMessage>                        $messages
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly string $globalRollup,
        public readonly string $projectRollup,
        public readonly array $integrations,
        public readonly string $jiraStatus,
        public readonly MessageRef|string|null $jiraMessage,
        public readonly string $gitStatus,
        public readonly MessageRef|string|null $gitMessage,
        public readonly string $linearStatus,
        public readonly MessageRef|string|null $linearMessage,
        public readonly ?string $gitProvider,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     * @param list<ResponseMessage>                        $messages
     */
    public static function fromEvaluation(
        ConfigReadinessEvaluation $evaluation,
        array $integrations,
        ?string $projectGitProvider,
        array $messages = [],
    ): self {
        $flat = self::flatAliasesFromIntegrations($integrations, $projectGitProvider);
        $error = $evaluation->success
            ? null
            : 'config.validate.error_readiness_failed';

        foreach ($evaluation->notices as $notice) {
            $messages[] = ResponseMessage::notice($notice);
        }

        return new self(
            $evaluation->success,
            $error,
            $evaluation->globalRollup,
            $evaluation->projectRollup,
            $integrations,
            $flat['jiraStatus'],
            $flat['jiraMessage'],
            $flat['gitStatus'],
            $flat['gitMessage'],
            $flat['linearStatus'],
            $flat['linearMessage'],
            $projectGitProvider,
            $messages,
        );
    }

    /**
     * Legacy factory for unit tests and backward compatibility.
     *
     * @param list<ResponseMessage> $messages
     */
    public static function create(
        string $jiraStatus,
        MessageRef|string|null $jiraMessage,
        string $gitStatus,
        MessageRef|string|null $gitMessage,
        string $linearStatus = self::STATUS_SKIPPED,
        MessageRef|string|null $linearMessage = null,
        ?string $gitProvider = null,
        array $messages = [],
    ): self {
        $integrations = [];
        if ($jiraStatus !== self::STATUS_SKIPPED) {
            $integrations['jira'] = new ConfigValidateIntegrationPing($jiraStatus, $jiraMessage);
        }
        if ($linearStatus !== self::STATUS_SKIPPED) {
            $integrations['linear'] = new ConfigValidateIntegrationPing($linearStatus, $linearMessage);
        }
        if ($gitStatus !== self::STATUS_SKIPPED && $gitProvider !== null) {
            $integrations[$gitProvider] = new ConfigValidateIntegrationPing($gitStatus, $gitMessage);
        }

        $success = ($jiraStatus !== self::STATUS_FAIL)
            && ($gitStatus !== self::STATUS_FAIL)
            && ($linearStatus !== self::STATUS_FAIL);

        $globalRollup = $success ? ConfigReadinessEvaluator::ROLLUP_OK : ConfigReadinessEvaluator::ROLLUP_FAIL;

        return new self(
            $success,
            $success ? null : 'config.validate.error_readiness_failed',
            $globalRollup,
            ConfigReadinessEvaluator::ROLLUP_NOT_APPLICABLE,
            $integrations,
            $jiraStatus,
            $jiraMessage,
            $gitStatus,
            $gitMessage,
            $linearStatus,
            $linearMessage,
            $gitProvider,
            $messages,
        );
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    public function withAdditionalMessages(array $messages): self
    {
        return new self(
            $this->success,
            $this->getError(),
            $this->globalRollup,
            $this->projectRollup,
            $this->integrations,
            $this->jiraStatus,
            $this->jiraMessage,
            $this->gitStatus,
            $this->gitMessage,
            $this->linearStatus,
            $this->linearMessage,
            $this->gitProvider,
            array_merge($this->messages, $messages),
        );
    }

    public static function error(string $error): self
    {
        return new self(
            false,
            $error,
            ConfigReadinessEvaluator::ROLLUP_FAIL,
            ConfigReadinessEvaluator::ROLLUP_NOT_APPLICABLE,
            [],
            self::STATUS_FAIL,
            null,
            self::STATUS_FAIL,
            null,
            self::STATUS_FAIL,
            null,
            null,
        );
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     *
     * @return array{
     *     jiraStatus: string,
     *     jiraMessage: MessageRef|string|null,
     *     gitStatus: string,
     *     gitMessage: MessageRef|string|null,
     *     linearStatus: string,
     *     linearMessage: MessageRef|string|null
     * }
     */
    private static function flatAliasesFromIntegrations(array $integrations, ?string $projectGitProvider): array
    {
        $jira = $integrations['jira'] ?? null;
        $linear = $integrations['linear'] ?? null;
        $gitKey = $projectGitProvider === 'github' ? 'github' : ($projectGitProvider === 'gitlab' ? 'gitlab' : null);
        $git = $gitKey !== null ? ($integrations[$gitKey] ?? null) : null;

        return [
            'jiraStatus' => $jira !== null ? $jira->status : self::STATUS_SKIPPED,
            'jiraMessage' => $jira?->message,
            'linearStatus' => $linear !== null ? $linear->status : self::STATUS_SKIPPED,
            'linearMessage' => $linear?->message,
            'gitStatus' => $git !== null ? $git->status : self::STATUS_SKIPPED,
            'gitMessage' => $git?->message,
        ];
    }
}
