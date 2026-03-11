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
        $this->tryAssignIssueToCurrentUser($key);
        $projectKey = $this->gitRepository->getProjectKeyFromIssueKey($key);
        $transitionId = $this->resolveTransitionId($key, $projectKey);
        if ($transitionId !== null) {
            $this->executeTransitionWithLogging($key, $transitionId);
        }

        return 0;
    }

    protected function tryAssignIssueToCurrentUser(string $key): void
    {
        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.assigning', ['key' => $key])}");
            $this->jiraService->assignIssue($key);
        } catch (ApiException $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.assign_error', ['error' => $e->getMessage()]));
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.assign_error', ['error' => $e->getMessage()]));
        }
    }

    protected function resolveTransitionId(string $key, string $projectKey): ?int
    {
        $projectConfig = $this->gitRepository->readProjectConfig();
        if (isset($projectConfig['projectKey']) && $projectConfig['projectKey'] === $projectKey && isset($projectConfig['transitionId'])) {
            $id = (int) $projectConfig['transitionId'];
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.using_cached_transition', ['id' => $id])}");

            return $id;
        }

        return $this->promptForTransitionId($key, $projectKey);
    }

    protected function promptForTransitionId(string $key, string $projectKey): ?int
    {
        try {
            $transitions = $this->jiraService->getTransitions($key);
            if ($transitions === []) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.no_transitions', ['key' => $key]));

                return null;
            }
            $options = array_map(fn (array $t) => "{$t['name']} (ID: {$t['id']})", $transitions);
            $selected = $this->logger->choice($this->translator->trans('item.start.select_transition'), $options);
            preg_match('/ID: (\d+)\)$/', $selected, $matches);
            if (! isset($matches[1])) {
                throw new \RuntimeException('Unable to extract transition ID from selection');
            }
            $transitionId = (int) $matches[1];
            if ($this->logger->confirm($this->translator->trans('item.start.save_transition', ['project' => $projectKey]), true)) {
                $this->gitRepository->writeProjectConfig(['projectKey' => $projectKey, 'transitionId' => $transitionId]);
                $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.transition_saved', ['project' => $projectKey])}");
            }

            return $transitionId;
        } catch (ApiException $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_error', ['error' => $e->getMessage()]));
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);

            return null;
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_error', ['error' => $e->getMessage()]));

            return null;
        }
    }

    protected function executeTransitionWithLogging(string $key, int $transitionId): void
    {
        try {
            $this->jiraService->transitionIssue($key, $transitionId);
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_success', ['key' => $key]));
        } catch (ApiException $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_exec_error', ['error' => $e->getMessage()]));
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.transition_exec_error', ['error' => $e->getMessage()]));
        }
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

        // Default: create new branch from the most advanced base ref
        $resolvedBase = $this->gitRepository->resolveLatestBaseBranch($this->baseBranch);
        if ($resolvedBase !== $this->baseBranch) {
            $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.start.using_advanced_base', ['configured' => $this->baseBranch, 'resolved' => $resolvedBase])}");
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.creating_branch', ['branch' => $defaultBranchName]));
        $this->gitRepository->createBranch($defaultBranchName, $resolvedBase);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.start.success', ['branch' => $defaultBranchName, 'base' => $resolvedBase]));
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
