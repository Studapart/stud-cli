<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\WorkflowOutput;

/**
 * Interactive prompts for stud config:project-init (keeps ConfigProjectInitHandler within size limits).
 */
class ConfigProjectInitPromptCollector
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitSetupService $gitSetupService,
        mixed $_translator,
        private readonly WorkflowOutput $logger,
        private readonly GitTokenPromptResolver $gitTokenPromptResolver,
    ) {
        unset($_translator);
    }

    /**
     * @return array<string, mixed> input-keyed patches
     */
    public function collect(): array
    {
        $existing = $this->gitRepository->readProjectConfig();
        $this->logger->addSection(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('config.project_init.interactive_title'));
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('config.project_init.interactive_hint'));

        $patches = [];
        $patches = array_merge($patches, $this->promptProjectKey($existing));
        $patches = array_merge($patches, $this->promptJiraDefaultProject($existing));
        $patches = array_merge($patches, $this->promptConfluenceDefaultSpace($existing));
        $patches = array_merge($patches, $this->promptTransitionId($existing));
        $patches = array_merge($patches, $this->promptBaseBranch($existing));
        $patches = array_merge($patches, $this->promptGitProvider($existing));
        $patches = array_merge($patches, $this->promptGitlabInstanceUrl($existing));
        $patches = array_merge($patches, $this->promptGithubToken($existing));
        $patches = array_merge($patches, $this->promptGitlabToken($existing));

        return $patches;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptProjectKey(array $existing): array
    {
        $current = isset($existing['projectKey']) ? (string) $existing['projectKey'] : '';
        $answer = $this->logger->ask(
            MessageRef::key('config.project_init.prompt_project_key'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['projectKey' => strtoupper(trim((string) $answer))];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptJiraDefaultProject(array $existing): array
    {
        $current = isset($existing['JIRA_DEFAULT_PROJECT']) ? (string) $existing['JIRA_DEFAULT_PROJECT'] : '';
        $answer = $this->logger->ask(
            MessageRef::key('config.project_init.prompt_jira_default_project'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['jiraDefaultProject' => strtoupper(trim((string) $answer))];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptConfluenceDefaultSpace(array $existing): array
    {
        $current = isset($existing['CONFLUENCE_DEFAULT_SPACE']) ? (string) $existing['CONFLUENCE_DEFAULT_SPACE'] : '';
        $answer = $this->logger->ask(
            MessageRef::key('config.project_init.prompt_confluence_default_space'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['confluenceDefaultSpace' => trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptTransitionId(array $existing): array
    {
        $current = isset($existing['transitionId']) ? (string) (int) $existing['transitionId'] : '';
        $answer = $this->logger->ask(
            MessageRef::key('config.project_init.prompt_transition_id'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }
        if (! ctype_digit(trim((string) $answer))) {
            $this->logger->addError(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('config.project_init.invalid_transition_id')
            );

            return [];
        }

        return ['transitionId' => (int) trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptBaseBranch(array $existing): array
    {
        $current = isset($existing['baseBranch']) ? (string) $existing['baseBranch'] : '';
        $detected = $this->gitSetupService->detectDefaultBaseBranchName();
        if ($detected !== null) {
            $this->logger->addNote(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('config.base_branch_detected', ['branch' => $detected])
            );
        }
        $default = $current !== '' ? $current : ($detected ?? 'develop');
        $answer = $this->logger->ask(
            MessageRef::key('config.project_init.prompt_base_branch'),
            $default
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['baseBranch' => trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGitProvider(array $existing): array
    {
        $current = isset($existing['gitProvider']) ? (string) $existing['gitProvider'] : '';
        $parsed = $this->gitRepository->parseGitUrl('origin');
        $detected = $parsed['provider'] ?? null;
        if ($current !== '' && in_array($current, ['github', 'gitlab'], true)) {
            return [];
        }
        if ($detected !== null) {
            $this->logger->addNote(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('config.git_provider_detected', ['provider' => $detected])
            );
        }
        $choice = $this->logger->choice(
            MessageRef::key('config.project_init.prompt_git_provider'),
            ['github', 'gitlab'],
            in_array($detected, ['github', 'gitlab'], true) ? $detected : 'github'
        );

        return ['gitProvider' => $choice];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGitlabInstanceUrl(array $existing): array
    {
        $current = isset($existing['gitlabInstanceUrl']) ? (string) $existing['gitlabInstanceUrl'] : '';
        $answer = $this->logger->ask(
            MessageRef::key('config.project_init.prompt_gitlab_instance_url'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['gitlabInstanceUrl' => trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGithubToken(array $existing): array
    {
        $has = isset($existing['githubToken']) && is_string($existing['githubToken']) && trim($existing['githubToken']) !== '';
        $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('config.project_init.prompt_github_token_hint'));
        $answer = $this->logger->askHidden(MessageRef::key('config.project_init.prompt_github_token'));
        if ($has && $answer !== null && trim((string) $answer) === '.') {
            return [];
        }
        $token = $this->gitTokenPromptResolver->tokenFromUserInput($answer === null ? null : (string) $answer);
        if ($token === null) {
            return [];
        }

        return ['githubToken' => $token];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGitlabToken(array $existing): array
    {
        $has = isset($existing['gitlabToken']) && is_string($existing['gitlabToken']) && trim($existing['gitlabToken']) !== '';
        $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('config.project_init.prompt_gitlab_token_hint'));
        $answer = $this->logger->askHidden(MessageRef::key('config.project_init.prompt_gitlab_token'));
        if ($has && $answer !== null && trim((string) $answer) === '.') {
            return [];
        }
        $token = $this->gitTokenPromptResolver->tokenFromUserInput($answer === null ? null : (string) $answer);
        if ($token === null) {
            return [];
        }

        return ['gitlabToken' => $token];
    }
}
