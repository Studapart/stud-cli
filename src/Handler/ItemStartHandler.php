<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
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
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.section', ['key' => $key]));

        $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.fetching', ['key' => $key])}");

        try {
            $issue = $this->jiraService->getIssue($key);
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('item.start.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return 1;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.error_not_found', ['key' => $key]));

            return 1;
        }

        // Handle Jira transition if enabled
        if (! empty($this->jiraConfig['JIRA_TRANSITION_ENABLED'])) {
            $this->handleTransition($key, $issue);
            // All error handling is done inside handleTransition with warnings/errors
            // Branch creation continues regardless of transition success/failure
        }

        $prefix = $this->getBranchPrefixFromIssueType($issue->issueType);
        // Use Symfony's AsciiSlugger to create a clean, lowercase slug for the branch name
        $slugger = new AsciiSlugger();
        $slugValue = $slugger->slug($issue->title)->lower()->toString();
        $branchName = "{$prefix}/{$key}-{$slugValue}";

        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.generated_branch', ['branch' => $branchName])}");

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.fetching_changes'));
        $this->gitRepository->fetch();

        // Check for existing branches before creating
        $existingBranches = $this->gitRepository->findBranchesByIssueKey($key);
        $branchAction = $this->determineBranchAction($branchName, $existingBranches);

        $this->executeBranchAction($branchAction, $branchName);

        return 0;
    }

    protected function handleTransition(string $key, \App\DTO\WorkItem $issue): int
    {
        // Step 1: Assign issue to current user
        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.assigning', ['key' => $key])}");
            $this->jiraService->assignIssue($key);
        } catch (ApiException $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.assign_error', ['error' => $e->getMessage()]));
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
            // Continue even if assignment fails
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.assign_error', ['error' => $e->getMessage()]));
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
                    $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.no_transitions', ['key' => $key]));

                    return 0; // Skip transition, continue with branch creation
                }

                // Display transitions and prompt user
                $transitionOptions = [];
                foreach ($transitions as $transition) {
                    $transitionOptions[] = "{$transition['name']} (ID: {$transition['id']})";
                }

                $selectedDisplay = $this->logger->choice(
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
                $saveChoice = $this->logger->confirm(
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
            } catch (ApiException $e) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_error', ['error' => $e->getMessage()]));
                $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);

                return 0; // Skip transition, continue with branch creation
            } catch (\Exception $e) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_error', ['error' => $e->getMessage()]));

                return 0; // Skip transition, continue with branch creation
            }
        } else {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.using_cached_transition', ['id' => $transitionId])}");
        }

        // Step 4: Execute transition
        if ($transitionId !== null) {
            try {
                $this->jiraService->transitionIssue($key, $transitionId);
                $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_success', ['key' => $key]));
            } catch (ApiException $e) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_exec_error', ['error' => $e->getMessage()]));
                $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
                // Continue with branch creation even if transition fails
            } catch (\Exception $e) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_exec_error', ['error' => $e->getMessage()]));
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

    /**
     * Executes the determined branch action.
     *
     * @param array{action: string, branch: string} $branchAction
     */
    protected function executeBranchAction(array $branchAction, string $defaultBranchName): void
    {
        if ($branchAction['action'] === BranchAction::SWITCH_LOCAL) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.switching_branch', ['branch' => $branchAction['branch']]));
            $this->gitRepository->switchBranch($branchAction['branch']);
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        if ($branchAction['action'] === BranchAction::SWITCH_REMOTE) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.switching_remote_branch', ['branch' => $branchAction['branch']]));
            $this->gitRepository->switchToRemoteBranch($branchAction['branch']);
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        // Default: create new branch
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.creating_branch', ['branch' => $defaultBranchName]));
        $this->gitRepository->createBranch($defaultBranchName, $this->baseBranch);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.success', ['branch' => $defaultBranchName, 'base' => $this->baseBranch]));
    }

    /**
     * Determines what action to take based on existing branches.
     *
     * @param string $generatedBranchName The generated branch name
     * @param array{local: array<string>, remote: array<string>} $existingBranches Existing branches found
     * @return array{action: string, branch: string} Action to take and branch name
     */
    protected function determineBranchAction(string $generatedBranchName, array $existingBranches): array
    {
        $localBranches = $existingBranches['local'];
        $remoteBranches = $existingBranches['remote'];

        // Check if local branch exists
        if (in_array($generatedBranchName, $localBranches, true)) {
            return ['action' => BranchAction::SWITCH_LOCAL, 'branch' => $generatedBranchName];
        }

        // Check if remote branch exists
        if (in_array($generatedBranchName, $remoteBranches, true)) {
            return ['action' => BranchAction::SWITCH_REMOTE, 'branch' => $generatedBranchName];
        }

        // If any local branch exists, switch to first one
        if (! empty($localBranches)) {
            return ['action' => BranchAction::SWITCH_LOCAL, 'branch' => $localBranches[0]];
        }

        // If any remote branch exists, switch to first one
        if (! empty($remoteBranches)) {
            return ['action' => BranchAction::SWITCH_REMOTE, 'branch' => $remoteBranches[0]];
        }

        // No existing branches, create new one
        return ['action' => BranchAction::CREATE, 'branch' => $generatedBranchName];
    }
}
