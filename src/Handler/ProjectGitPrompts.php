<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\Prompt\PromptInterface;

/**
 * Asks for project git settings: base branch, hosting provider, GitLab instance URL, and hosting tokens.
 */
class ProjectGitPrompts
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitSetupService $gitSetupService,
        private readonly PromptInterface $prompt,
        private readonly GitTokenPromptResolver $gitTokenPromptResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public function baseBranchPatches(array $existing, WorkflowEntryRecorder $recorder): array
    {
        $current = isset($existing['baseBranch']) ? (string) $existing['baseBranch'] : '';
        $detected = $this->gitSetupService->detectDefaultBaseBranchName();
        if ($detected !== null) {
            $recorder->addNote(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('config.base_branch_detected', ['branch' => $detected])
            );
        }
        $default = $current !== '' ? $current : ($detected ?? 'develop');
        $answer = $this->prompt->ask(
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
    public function hostingPatches(array $existing, WorkflowEntryRecorder $recorder): array
    {
        return array_merge(
            $this->promptGitProvider($existing, $recorder),
            $this->promptGitlabInstanceUrl($existing),
            $this->promptToken($existing, $recorder, 'githubToken', 'config.project_init.prompt_github_token'),
            $this->promptToken($existing, $recorder, 'gitlabToken', 'config.project_init.prompt_gitlab_token'),
        );
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function promptGitProvider(array $existing, WorkflowEntryRecorder $recorder): array
    {
        $current = isset($existing['gitProvider']) ? (string) $existing['gitProvider'] : '';
        $parsed = $this->gitRepository->parseGitUrl('origin');
        $detected = $parsed['provider'] ?? null;
        if ($current !== '' && in_array($current, ['github', 'gitlab'], true)) {
            return [];
        }
        if ($detected !== null) {
            $recorder->addNote(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('config.git_provider_detected', ['provider' => $detected])
            );
        }
        $choice = $this->prompt->choice(
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
    private function promptGitlabInstanceUrl(array $existing): array
    {
        $current = isset($existing['gitlabInstanceUrl']) ? (string) $existing['gitlabInstanceUrl'] : '';
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_gitlab_instance_url'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['gitlabInstanceUrl' => trim((string) $answer)];
    }

    /**
     * Prompts for a hosting token; "." keeps an existing token.
     *
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function promptToken(array $existing, WorkflowEntryRecorder $recorder, string $inputKey, string $messageKey): array
    {
        $has = isset($existing[$inputKey]) && is_string($existing[$inputKey]) && trim($existing[$inputKey]) !== '';
        $recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key($messageKey . '_hint'));
        $answer = $this->prompt->askHidden(MessageRef::key($messageKey));
        if ($has && $answer !== null && trim((string) $answer) === '.') {
            return [];
        }
        $token = $this->gitTokenPromptResolver->tokenFromUserInput($answer === null ? null : (string) $answer);
        if ($token === null) {
            return [];
        }

        return [$inputKey => $token];
    }
}
