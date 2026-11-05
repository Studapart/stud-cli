<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\ConfigValidatePingPorts;
use App\DTO\ConfigValidatePlan;
use App\DTO\ResponseMessage;
use App\Enum\GitProvider;
use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Response\ConfigValidateResponse;
use App\Service\ConfigReadinessEvaluator;
use App\Service\IssueTrackerPort;

/**
 * Handler for config:validate: pings configured integrations and evaluates readiness (SCI-218).
 */
class ConfigValidateHandler
{
    private const MAX_REASON_LENGTH = 120;

    public function __construct(
        private readonly ConfigValidatePingPorts $ports,
        private readonly ConfigValidatePlan $plan,
        private readonly ConfigReadinessEvaluator $readinessEvaluator = new ConfigReadinessEvaluator(),
    ) {
    }

    public function handle(): ConfigValidateResponse
    {
        $integrations = [];
        $this->pingJira($integrations);
        $this->pingLinear($integrations);
        $this->pingGitHosts($integrations);

        $evaluation = $this->readinessEvaluator->evaluate($integrations, $this->plan);
        $response = ConfigValidateResponse::fromEvaluation(
            $evaluation,
            $integrations,
            $this->plan->projectGit?->integrationKey(),
        );
        if ($this->plan->projectSelectionError === null) {
            return $response;
        }

        return $response->withAdditionalMessages([
            ResponseMessage::notice($this->plan->projectSelectionError),
        ]);
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function pingJira(array &$integrations): void
    {
        if (! $this->plan->probeJira) {
            $integrations['jira'] = ConfigValidateIntegrationPing::skipped();

            return;
        }

        if ($this->ports->jiraIssueTracker === null) {
            $integrations['jira'] = $this->ports->jiraSetupError !== null
                ? ConfigValidateIntegrationPing::fail($this->ports->jiraSetupError)
                : ConfigValidateIntegrationPing::failWithKey('config.validate.error_jira_not_configured');

            return;
        }

        $integrations['jira'] = $this->pingIssueTracker($this->ports->jiraIssueTracker, 'config.validate.error_jira_ping');
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function pingLinear(array &$integrations): void
    {
        if (! $this->plan->probeLinear) {
            $integrations['linear'] = ConfigValidateIntegrationPing::skipped();

            return;
        }

        if ($this->ports->linearIssueTracker === null) {
            $integrations['linear'] = $this->ports->linearSetupError !== null
                ? ConfigValidateIntegrationPing::fail($this->ports->linearSetupError)
                : ConfigValidateIntegrationPing::failWithKey('config.validate.error_linear_not_configured');

            return;
        }

        $integrations['linear'] = $this->pingIssueTracker($this->ports->linearIssueTracker, 'config.validate.error_linear_ping');
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function pingGitHosts(array &$integrations): void
    {
        if (! $this->plan->probeGit) {
            $this->recordSkippedGitHosts($integrations);

            return;
        }

        if ($this->plan->includeGithub) {
            $integrations[GitProvider::Github->integrationKey()] = $this->pingProjectGit(GitProvider::Github);
        }
        if ($this->plan->includeGitlab) {
            $integrations[GitProvider::Gitlab->integrationKey()] = $this->pingProjectGit(GitProvider::Gitlab);
        }
    }

    private function pingProjectGit(GitProvider $host): ConfigValidateIntegrationPing
    {
        $isProjectHost = $this->plan->projectGit === $host;
        $port = $isProjectHost
            ? $this->ports->projectGitHosting
            : ($host === GitProvider::Github ? $this->ports->githubSmokeHosting : $this->ports->gitlabSmokeHosting);

        if ($port === null) {
            if ($isProjectHost) {
                return ConfigValidateIntegrationPing::failWithKey('config.validate.error_git_not_configured');
            }

            return ConfigValidateIntegrationPing::skipped();
        }

        try {
            if ($isProjectHost) {
                $port->getLabels();
            } else {
                $port->getAuthenticatedUser();
            }

            return ConfigValidateIntegrationPing::ok();
        } catch (ApiException $e) {
            return ConfigValidateIntegrationPing::failWithKey('config.validate.error_git_ping', ['error' => $this->shortReason($e)]);
        } catch (\Throwable $e) {
            return ConfigValidateIntegrationPing::failWithKey('config.validate.error_git_ping', ['error' => $this->shortReason($e)]);
        }
    }

    private function pingIssueTracker(IssueTrackerPort $tracker, string $errorKey): ConfigValidateIntegrationPing
    {
        try {
            $tracker->ping();

            return ConfigValidateIntegrationPing::ok();
        } catch (ApiException $e) {
            return ConfigValidateIntegrationPing::failWithKey($errorKey, ['error' => $this->shortReason($e)]);
        } catch (IssueTrackerException $e) {
            return ConfigValidateIntegrationPing::fail($e->messageRef);
        } catch (\Throwable $e) {
            return ConfigValidateIntegrationPing::failWithKey($errorKey, ['error' => $this->shortReason($e)]);
        }
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     */
    private function recordSkippedGitHosts(array &$integrations): void
    {
        if ($this->plan->includeGithub) {
            $integrations[GitProvider::Github->integrationKey()] = ConfigValidateIntegrationPing::skipped();
        }
        if ($this->plan->includeGitlab) {
            $integrations[GitProvider::Gitlab->integrationKey()] = ConfigValidateIntegrationPing::skipped();
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
