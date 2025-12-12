<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ItemStartHandler
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly array $jiraConfig,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, string $key): int
    {
        $key = strtoupper($key);
        $io->section($this->translator->trans('item.start.section', ['key' => $key]));

        $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.fetching', ['key' => $key])}");

        try {
            $issue = $this->jiraService->getIssue($key);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.start.error_not_found', ['key' => $key]));

            return 1;
        }

        // Handle Jira transition if enabled
        if (! empty($this->jiraConfig['JIRA_TRANSITION_ENABLED'])) {
            $this->handleTransition($io, $key, $issue);
            // All error handling is done inside handleTransition with warnings/errors
            // Branch creation continues regardless of transition success/failure
        }

        $prefix = $this->getBranchPrefixFromIssueType($issue->issueType);
        // Use Symfony's AsciiSlugger to create a clean, lowercase slug for the branch name
        $slugger = new AsciiSlugger();
        $slugValue = $slugger->slug($issue->title)->lower()->toString();
        $branchName = "{$prefix}/{$key}-{$slugValue}";

        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.generated_branch', ['branch' => $branchName])}");

        $io->text($this->translator->trans('item.start.fetching_changes'));
        $this->gitRepository->fetch();

        $io->text($this->translator->trans('item.start.creating_branch', ['branch' => $branchName]));
        $this->gitRepository->createBranch($branchName, $this->baseBranch);

        $io->success($this->translator->trans('item.start.success', ['branch' => $branchName, 'base' => $this->baseBranch]));

        return 0;
    }

    protected function handleTransition(SymfonyStyle $io, string $key, \App\DTO\WorkItem $issue): int
    {
        // Step 1: Assign issue to current user
        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.assigning', ['key' => $key])}");
            $this->jiraService->assignIssue($key);
        } catch (\Exception $e) {
            $io->warning($this->translator->trans('item.start.assign_error', ['error' => $e->getMessage()]));
            // Continue even if assignment fails
        }

        // Step 2: Check cache for transition ID
        $projectKey = $this->gitRepository->getProjectKeyFromIssueKey($key);
        $projectConfig = $this->gitRepository->readProjectConfig();
        $cachedTransitionId = null;

        if (isset($projectConfig['projectKey']) && $projectConfig['projectKey'] === $projectKey && isset($projectConfig['transitionId'])) {
            $cachedTransitionId = (int) $projectConfig['transitionId'];
        }

        $transitionId = $cachedTransitionId;

        // Step 3: If not cached, interactive lookup
        if ($transitionId === null) {
            try {
                $transitions = $this->jiraService->getTransitions($key);

                if (empty($transitions)) {
                    $io->warning($this->translator->trans('item.start.no_transitions', ['key' => $key]));

                    return 0; // Skip transition, continue with branch creation
                }

                // Display transitions and prompt user
                $transitionOptions = [];
                foreach ($transitions as $transition) {
                    $transitionOptions[] = "{$transition['name']} (ID: {$transition['id']})";
                }

                $selectedDisplay = $io->choice(
                    $this->translator->trans('item.start.select_transition'),
                    $transitionOptions
                );

                // Extract transition ID from selection
                // SymfonyStyle::choice() validates input and only returns one of the provided options,
                // which all match our regex pattern, so this will always succeed
                preg_match('/ID: (\d+)\)$/', $selectedDisplay, $matches);
                if (! isset($matches[1])) {
                    throw new \RuntimeException('Unable to extract transition ID from selection');
                }

                $transitionId = (int) $matches[1];

                // Ask if user wants to save the choice
                $saveChoice = $io->confirm(
                    $this->translator->trans('item.start.save_transition', ['project' => $projectKey]),
                    true
                );

                if ($saveChoice) {
                    $this->gitRepository->writeProjectConfig([
                        'projectKey' => $projectKey,
                        'transitionId' => $transitionId,
                    ]);
                    $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.transition_saved', ['project' => $projectKey])}");
                }
            } catch (\Exception $e) {
                $io->warning($this->translator->trans('item.start.transition_error', ['error' => $e->getMessage()]));

                return 0; // Skip transition, continue with branch creation
            }
        } else {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.using_cached_transition', ['id' => $transitionId])}");
        }

        // Step 4: Execute transition
        if ($transitionId !== null) {
            try {
                $this->jiraService->transitionIssue($key, $transitionId);
                $io->success($this->translator->trans('item.start.transition_success', ['key' => $key]));
            } catch (\Exception $e) {
                $io->warning($this->translator->trans('item.start.transition_exec_error', ['error' => $e->getMessage()]));
                // Continue with branch creation even if transition fails
            }
        }

        return 0;
    }

    protected function getBranchPrefixFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }
}
