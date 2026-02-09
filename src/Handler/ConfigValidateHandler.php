<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\ConfigValidateResponse;
use App\Service\GitProviderInterface;
use App\Service\JiraService;

/**
 * Handler for config:validate: validates that config is loadable and that Jira
 * and the Git provider are reachable with the configured credentials.
 */
class ConfigValidateHandler
{
    private const MAX_REASON_LENGTH = 120;

    public function __construct(
        private readonly ?JiraService $jiraService,
        private readonly ?GitProviderInterface $gitProvider,
        private readonly bool $skipJira,
        private readonly bool $skipGit
    ) {
    }

    public function handle(): ConfigValidateResponse
    {
        $jiraStatus = $this->resolveJiraStatus();
        $gitStatus = $this->resolveGitStatus();

        return ConfigValidateResponse::create(
            $jiraStatus['status'],
            $jiraStatus['message'],
            $gitStatus['status'],
            $gitStatus['message']
        );
    }

    /**
     * @return array{status: string, message: ?string}
     */
    protected function resolveJiraStatus(): array
    {
        if ($this->skipJira) {
            return ['status' => ConfigValidateResponse::STATUS_SKIPPED, 'message' => null];
        }

        if ($this->jiraService === null) {
            return ['status' => ConfigValidateResponse::STATUS_FAIL, 'message' => 'Jira not configured'];
        }

        try {
            $this->jiraService->getProjects();

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
        if ($this->skipGit) {
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

    protected function shortReason(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (strlen($message) <= self::MAX_REASON_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_REASON_LENGTH - 3) . '...';
    }
}
