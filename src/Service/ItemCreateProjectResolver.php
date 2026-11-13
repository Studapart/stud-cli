<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ItemCreateMatch;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Service\Prompt\PromptInterface;

class ItemCreateProjectResolver
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?JiraApiClient $jiraService,
        private readonly PromptInterface $prompt,
        private readonly ItemCreateLinearLookup $linear = new ItemCreateLinearLookup(),
    ) {
    }

    public function resolveProjectKey(bool $interactive, ?string $project): ?string
    {
        if ($project !== null && trim($project) !== '') {
            return trim($project);
        }
        $config = $this->gitRepository->readProjectConfig();
        /** @var array<string, mixed> $config */
        $defaultProject = isset($config['JIRA_DEFAULT_PROJECT']) ? (string) $config['JIRA_DEFAULT_PROJECT'] : null;
        if ($defaultProject !== null && trim($defaultProject) !== '') {
            return trim($defaultProject);
        }
        if (! $interactive) {
            return null;
        }

        return $this->prompt->ask(MessageRef::key('item.create.prompt_project'));
    }

    public function resolveLinearScopeKey(?string $cliProjectKey = null): ?string
    {
        /** @var array<string, mixed> $config */
        $config = $this->gitRepository->readProjectConfig();
        $fromConfig = $this->linear->scope->resolveLinearTeamKey($config);
        if ($fromConfig !== null) {
            return $fromConfig;
        }
        if ($cliProjectKey !== null && trim($cliProjectKey) !== '') {
            return strtoupper(trim($cliProjectKey));
        }

        return null;
    }

    /**
     * Finds the project in Jira, then in Linear. The tag on a match is the tracker decision for the rest of the run.
     */
    public function ensureProjectExists(bool $interactive, string $projectKey, ?string $pinnedTracker = null): ItemCreateMatch|MessageRef|null
    {
        $match = $this->lookup($projectKey, null, $pinnedTracker);
        if ($match instanceof MessageRef || $match instanceof ItemCreateMatch || ! $interactive) {
            return $match;
        }

        $newKey = $this->prompt->ask(MessageRef::key('item.create.prompt_project_not_found', ['key' => $projectKey]));
        if ($newKey === null || trim($newKey) === '') {
            return null;
        }

        $typedKey = trim($newKey);

        return $this->lookup($typedKey, $typedKey, $pinnedTracker);
    }

    /**
     * Looks the key up in Jira, then in Linear. A pinned Linear run does not stop on a Jira error.
     */
    protected function lookup(string $projectKey, ?string $typedTeamKey, ?string $pinnedTracker): ItemCreateMatch|MessageRef|null
    {
        $jira = $this->findJiraProject($projectKey);
        if ($jira instanceof ItemCreateMatch) {
            return $jira;
        }
        if ($jira instanceof MessageRef && $pinnedTracker !== IssueTrackerProvider::Linear->value) {
            return $jira;
        }
        if ($jira === null && $this->jiraService !== null) {
            $this->linear->logger?->writeln(
                Logger::VERBOSITY_VERBOSE,
                sprintf('Jira project "%s" not found; trying Linear team lookup.', $projectKey),
            );
        }

        return $this->findLinearTeam($projectKey, $typedTeamKey);
    }

    /**
     * Returns a Jira match, an existing error, or null when the key is not a Jira project.
     */
    protected function findJiraProject(string $projectKey): ItemCreateMatch|MessageRef|null
    {
        if ($this->jiraService === null) {
            return null;
        }

        try {
            $project = $this->jiraService->getProject($projectKey);
        } catch (ApiException $exception) {
            return $this->jiraFailure($exception, $projectKey);
        }

        return new ItemCreateMatch($project->key, IssueTrackerProvider::Jira->value);
    }

    /**
     * Maps a Jira lookup failure to an existing message. Null means the key was not found.
     */
    protected function jiraFailure(ApiException $exception, string $projectKey): ?MessageRef
    {
        $status = $exception->getStatusCode();
        if ($status === 401 || $status === 403) {
            return MessageRef::key('issue_tracker_provider.missing_jira_configuration');
        }
        if ($status === 404) {
            return null;
        }

        return MessageRef::key('item.create.error_project_not_found', ['key' => $projectKey]);
    }

    /**
     * Returns a Linear team match, an existing error, or null when the team is absent.
     */
    protected function findLinearTeam(string $projectKey, ?string $typedTeamKey): ItemCreateMatch|MessageRef|null
    {
        if ($this->linear->client === null) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = $this->gitRepository->readProjectConfig();
        $linearTeamKey = $typedTeamKey ?? ($this->linear->scope->resolveLinearTeamKey($config) ?? $projectKey);

        try {
            $linearTeam = $this->linear->client->getTeamByKey($linearTeamKey);
        } catch (IssueTrackerException $exception) {
            return $exception->messageRef;
        } catch (ApiException $exception) {
            return $this->linearFailure($exception, $projectKey);
        }

        if ($linearTeam === null) {
            return null;
        }

        return new ItemCreateMatch($linearTeam->key, IssueTrackerProvider::Linear->value);
    }

    /**
     * Maps a Linear lookup failure to an existing message.
     */
    protected function linearFailure(ApiException $exception, string $projectKey): MessageRef
    {
        $status = $exception->getStatusCode();
        if ($status === 401 || $status === 403) {
            return MessageRef::key('issue_tracker_provider.missing_linear_api_key');
        }

        return MessageRef::key('item.create.error_project_not_found', ['key' => $projectKey]);
    }
}
