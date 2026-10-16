<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Guard\DTO\ProviderResolutionResult;
use App\Response\ConfigValidateResponse;
use App\Service\ConfigReadinessEvaluator;
use App\Service\GitHostingPort;
use App\Service\IssueTrackerPort;

/**
 * Handler for config:validate: pings configured integrations and evaluates readiness (SCI-218).
 */
class ConfigValidateHandler
{
    private const MAX_REASON_LENGTH = 120;

    public function __construct(
        private readonly ?IssueTrackerPort $jiraIssueTracker,
        private readonly ?IssueTrackerPort $linearIssueTracker,
        private readonly ?GitHostingPort $projectGitHosting,
        private readonly ?GitHostingPort $githubSmokeHosting,
        private readonly ?GitHostingPort $gitlabSmokeHosting,
        private readonly bool $skipJira,
        private readonly bool $skipGit,
        private readonly bool $skipLinear,
        private readonly bool $validateJira,
        private readonly bool $validateGit,
        private readonly bool $validateLinear,
        private readonly bool $validateGithubGlobally,
        private readonly bool $validateGitlabGlobally,
        private readonly ProviderResolutionResult $providerResolution,
        private readonly bool $inRepo,
        private readonly bool $hasProjectConfig,
        private readonly ?string $projectGitProvider,
        private readonly ConfigReadinessEvaluator $readinessEvaluator = new ConfigReadinessEvaluator(),
    ) {
    }

    public function handle(): ConfigValidateResponse
    {
        $integrations = [];
        $this->pingJira($integrations);
        $this->pingLinear($integrations);
        $this->pingGitHosts($integrations);

        $evaluation = $this->readinessEvaluator->evaluate(
            $integrations,
            $this->providerResolution,
            $this->inRepo,
            $this->hasProjectConfig,
            $this->projectGitProvider,
        );

        return ConfigValidateResponse::fromEvaluation(
            $evaluation,
            $integrations,
            $this->projectGitProvider,
        );
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function pingJira(array &$integrations): void
    {
        if ($this->skipJira || ! $this->validateJira) {
            return;
        }

        if ($this->jiraIssueTracker === null) {
            $integrations['jira'] = new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key('config.validate.error_jira_not_configured'),
            );

            return;
        }

        $integrations['jira'] = $this->pingIssueTracker($this->jiraIssueTracker, 'config.validate.error_jira_ping');
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function pingLinear(array &$integrations): void
    {
        if ($this->skipLinear || ! $this->validateLinear) {
            return;
        }

        if ($this->linearIssueTracker === null) {
            $integrations['linear'] = new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key('config.validate.error_linear_not_configured'),
            );

            return;
        }

        $integrations['linear'] = $this->pingIssueTracker($this->linearIssueTracker, 'config.validate.error_linear_ping');
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function pingGitHosts(array &$integrations): void
    {
        if ($this->skipGit || ! $this->validateGit) {
            return;
        }

        if ($this->validateGithubGlobally) {
            $integrations['github'] = $this->pingProjectGit('github');
        }
        if ($this->validateGitlabGlobally) {
            $integrations['gitlab'] = $this->pingProjectGit('gitlab');
        }
    }

    private function pingProjectGit(string $host): ConfigValidateIntegrationPing
    {
        $isProjectHost = $this->projectGitProvider === $host;
        $port = $isProjectHost ? $this->projectGitHosting : ($host === 'github' ? $this->githubSmokeHosting : $this->gitlabSmokeHosting);

        if ($port === null) {
            if ($isProjectHost) {
                return new ConfigValidateIntegrationPing(
                    ConfigValidateResponse::STATUS_FAIL,
                    MessageRef::key('config.validate.error_git_not_configured'),
                );
            }

            return new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED);
        }

        try {
            if ($isProjectHost) {
                $port->getLabels();
            } else {
                $port->getAuthenticatedUser();
            }

            return new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK);
        } catch (ApiException $e) {
            return new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key('config.validate.error_git_ping', ['error' => $this->shortReason($e)]),
            );
        } catch (\Throwable $e) {
            return new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key('config.validate.error_git_ping', ['error' => $this->shortReason($e)]),
            );
        }
    }

    private function pingIssueTracker(IssueTrackerPort $tracker, string $errorKey): ConfigValidateIntegrationPing
    {
        try {
            $tracker->ping();

            return new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK);
        } catch (ApiException $e) {
            return new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key($errorKey, ['error' => $this->shortReason($e)]),
            );
        } catch (IssueTrackerException $e) {
            return new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                $e->messageRef,
            );
        } catch (\Throwable $e) {
            return new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key($errorKey, ['error' => $this->shortReason($e)]),
            );
        }
    }

    private function shortReason(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (strlen($message) <= self::MAX_REASON_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_REASON_LENGTH - 3) . '...';
    }
}
