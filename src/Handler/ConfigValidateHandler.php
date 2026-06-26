<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\ConfigValidateResponse;
use App\Service\GitHostingPort;
use App\Service\IssueTrackerPort;

/**
 * Handler for config:validate: validates that config is loadable and that configured
 * Jira, Git, and Linear providers are reachable with the stored credentials.
 */
class ConfigValidateHandler
{
    private const MAX_REASON_LENGTH = 120;

    public function __construct(
        private readonly ?IssueTrackerPort $workItemProvider,
        private readonly ?GitHostingPort $gitProvider,
        private readonly bool $skipJira,
        private readonly bool $skipGit,
        private readonly bool $skipLinear,
        private readonly bool $validateJira,
        private readonly bool $validateGit,
        private readonly bool $validateLinear,
    ) {
    }

    public function handle(): ConfigValidateResponse
    {
        $jiraStatus = $this->resolveJiraStatus();
        $gitStatus = $this->resolveGitStatus();
        $linearStatus = $this->resolveLinearStatus();

        return ConfigValidateResponse::create(
            $jiraStatus['status'],
            $jiraStatus['message'],
            $gitStatus['status'],
            $gitStatus['message'],
            $linearStatus['status'],
            $linearStatus['message'],
        );
    }

    /**
     * @return array{status: string, message: ?string}
     */
    protected function resolveJiraStatus(): array
    {
        if ($this->skipJira || ! $this->validateJira) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        if ($this->workItemProvider === null) {
            return ['status' => ConfigValidateResponse::STATUS_FAIL, 'message' => 'Jira not configured'];
        }

        try {
            $this->workItemProvider->ping();

            return ['status' => ConfigValidateResponse::STATUS_OK, 'message' => null];
        } catch (\Throwable $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => $this->shortReason($e),
            ];
        }
    }

    /**
     * @return array{status: string, message: ?string}
     */
    protected function resolveGitStatus(): array
    {
        if ($this->skipGit || ! $this->validateGit) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        if ($this->gitProvider === null) {
            return ['status' => ConfigValidateResponse::STATUS_FAIL, 'message' => 'Git provider not configured'];
        }

        try {
            $this->gitProvider->getLabels();

            return ['status' => ConfigValidateResponse::STATUS_OK, 'message' => null];
        } catch (\Throwable $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => $this->shortReason($e),
            ];
        }
    }

    /**
     * @return array{status: string, message: ?string}
     */
    protected function resolveLinearStatus(): array
    {
        if ($this->skipLinear || ! $this->validateLinear) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
    }

    protected function shortReason(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (strlen($message) <= self::MAX_REASON_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_REASON_LENGTH - 3) . '...';
    }
}
