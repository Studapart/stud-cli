<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\DTO\Project;
use App\Exception\ApiException;
use App\Service\Prompt\PromptInterface;

class ItemCreateProjectResolver
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraApiClient $jiraService,
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

    public function ensureProjectExists(bool $interactive, string $projectKey): ?Project
    {
        try {
            return $this->jiraService->getProject($projectKey);
        } catch (ApiException) {
            /** @var array<string, mixed> $config */
            $config = $this->gitRepository->readProjectConfig();
            $linearTeamKey = $this->linear->scope->resolveLinearTeamKey($config) ?? $projectKey;
            $this->linear->logger?->writeln(
                Logger::VERBOSITY_VERBOSE,
                sprintf('Jira project "%s" not found; trying Linear team lookup.', $projectKey),
            );
            $linearTeam = $this->linear->client?->getTeamByKey($linearTeamKey);
            if ($linearTeam !== null) {
                return $linearTeam;
            }

            if (! $interactive) {
                return null;
            }
            $newKey = $this->prompt->ask(MessageRef::key('item.create.prompt_project_not_found', ['key' => $projectKey]));
            if ($newKey === null || trim($newKey) === '') {
                return null;
            }

            try {
                return $this->jiraService->getProject(trim($newKey));
            } catch (ApiException) {
                return null;
            }
        }
    }
}
