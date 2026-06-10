<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\WorkflowOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ItemStartHandler
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch,
        mixed $_translator,
        private readonly array $jiraConfig,
        private readonly WorkflowOutput $logger
    ) {
        unset($_translator);
    }

    public function handle(SymfonyStyle $io, string $key): int
    {
        $key = strtoupper($key);
        $this->logger->addSection(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.section', ['key' => $key]));

        $this->logger->addJiraLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.start.fetching', ['key' => $key]));

        try {
            $issue = $this->jiraService->getIssue($key);
        } catch (ApiException $e) {
            $this->logger->addErrorWithDetails(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('item.start.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return 1;
        } catch (\Exception $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.error_not_found', ['key' => $key]));

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

        $this->logger->addGitLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.start.generated_branch', ['branch' => $branchName]));

        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.fetching_changes'));
        $this->gitRepository->fetch();

        // Check for existing branches before creating
        $existingBranches = $this->gitBranchService->findBranchesByIssueKey($key);
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
            $this->logger->addJiraLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.start.assigning', ['key' => $key]));
            $this->jiraService->assignIssue($key);
        } catch (ApiException $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.assign_error', ['error' => $e->getMessage()]));
            $this->logger->addText(WorkflowOutput::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.assign_error', ['error' => $e->getMessage()]));
        }
    }

    protected function resolveTransitionId(string $key, string $projectKey): ?int
    {
        $projectConfig = $this->gitRepository->readProjectConfig();
        if (isset($projectConfig['projectKey']) && $projectConfig['projectKey'] === $projectKey && isset($projectConfig['transitionId'])) {
            $id = (int) $projectConfig['transitionId'];
            $this->logger->addJiraLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.start.using_cached_transition', ['id' => $id]));

            return $id;
        }

        return $this->promptForTransitionId($key, $projectKey);
    }

    protected function promptForTransitionId(string $key, string $projectKey): ?int
    {
        try {
            $transitions = $this->jiraService->getTransitions($key);
            if ($transitions === []) {
                $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.no_transitions', ['key' => $key]));

                return null;
            }
            $options = array_map(fn (array $t) => "{$t['name']} (ID: {$t['id']})", $transitions);
            $selected = $this->logger->choice(MessageRef::key('item.start.select_transition'), $options);
            preg_match('/ID: (\d+)\)$/', $selected, $matches);
            if (! isset($matches[1])) {
                throw new \RuntimeException('Unable to extract transition ID from selection');
            }
            $transitionId = (int) $matches[1];
            if ($this->logger->confirm(MessageRef::key('item.start.save_transition', ['project' => $projectKey]), true)) {
                $this->gitRepository->writeProjectConfig(['projectKey' => $projectKey, 'transitionId' => $transitionId]);
                $this->logger->addJiraLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.start.transition_saved', ['project' => $projectKey]));
            }

            return $transitionId;
        } catch (ApiException $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_error', ['error' => $e->getMessage()]));
            $this->logger->addText(WorkflowOutput::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);

            return null;
        } catch (\Exception $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_error', ['error' => $e->getMessage()]));

            return null;
        }
    }

    protected function executeTransitionWithLogging(string $key, int $transitionId): void
    {
        try {
            $this->jiraService->transitionIssue($key, $transitionId);
            $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_success', ['key' => $key]));
        } catch (ApiException $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_exec_error', ['error' => $e->getMessage()]));
            $this->logger->addText(WorkflowOutput::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_exec_error', ['error' => $e->getMessage()]));
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
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.switching_branch', ['branch' => $branchAction['branch']]));
            $this->gitBranchService->switchBranch($branchAction['branch']);
            $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        if ($branchAction['action'] === BranchAction::SWITCH_REMOTE) {
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.switching_remote_branch', ['branch' => $branchAction['branch']]));
            $this->gitBranchService->switchToRemoteBranch($branchAction['branch']);
            $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        // Default: create new branch from the most advanced base ref
        $resolvedBase = $this->gitBranchService->resolveLatestBaseBranch($this->baseBranch);
        if ($resolvedBase !== $this->baseBranch) {
            $this->logger->addGitLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.start.using_advanced_base', ['configured' => $this->baseBranch, 'resolved' => $resolvedBase]));
        }
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.creating_branch', ['branch' => $defaultBranchName]));
        $this->gitRepository->createBranch($defaultBranchName, $resolvedBase);
        $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.start.success', ['branch' => $defaultBranchName, 'base' => $resolvedBase]));
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
