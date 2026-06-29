<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Exception\ApiException;
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
        private readonly ?IssueTrackerPort $linearWorkItemProvider = null,
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
     * @return array{status: string, message: MessageRef|string|null}
     */
    protected function resolveJiraStatus(): array
    {
        if ($this->skipJira || ! $this->validateJira) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        if ($this->workItemProvider === null) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_jira_not_configured'),
            ];
        }

        try {
            $this->workItemProvider->ping();

            return ['status' => ConfigValidateResponse::STATUS_OK, 'message' => null];
        } catch (ApiException $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_jira_ping', ['error' => $this->shortReason($e)]),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_jira_ping', ['error' => $this->shortReason($e)]),
            ];
        }
    }

    /**
     * @return array{status: string, message: MessageRef|string|null}
     */
    protected function resolveGitStatus(): array
    {
        if ($this->skipGit || ! $this->validateGit) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        if ($this->gitProvider === null) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_git_not_configured'),
            ];
        }

        try {
            $this->gitProvider->getLabels();

            return ['status' => ConfigValidateResponse::STATUS_OK, 'message' => null];
        } catch (ApiException $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_git_ping', ['error' => $this->shortReason($e)]),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_git_ping', ['error' => $this->shortReason($e)]),
            ];
        }
    }

    /**
     * @return array{status: string, message: MessageRef|string|null}
     */
    protected function resolveLinearStatus(): array
    {
        if ($this->skipLinear || ! $this->validateLinear) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        if ($this->linearWorkItemProvider === null) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_linear_not_configured'),
            ];
        }

        try {
            $this->linearWorkItemProvider->ping();

            return ['status' => ConfigValidateResponse::STATUS_OK, 'message' => null];
        } catch (ApiException $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_linear_ping', ['error' => $this->shortReason($e)]),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => ConfigValidateResponse::STATUS_FAIL,
                'message' => MessageRef::key('config.validate.error_linear_ping', ['error' => $this->shortReason($e)]),
            ];
        }
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
