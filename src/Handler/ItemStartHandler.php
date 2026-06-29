<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\DTO\WorkflowRecorder;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\WorkflowResponse;
use App\Service\BranchNameGenerator;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\LinearTypeLabelResolver;
use App\Service\Prompt\PromptInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ItemStartHandler implements GitRepositoryAware, ProjectBaseBranchAware, WorkItemJiraAware
{
    private WorkflowEntryRecorder $recorder;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly IssueTrackerPort $provider,
        private readonly string $baseBranch,
        mixed $_translator,
        private readonly array $jiraConfig,
        private readonly PromptInterface $prompt,
        private readonly LinearTypeLabelResolver $linearTypeLabelResolver = new LinearTypeLabelResolver(),
    ) {
        unset($_translator);
    }

    public function handle(string $key): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $key = strtoupper($key);
        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.section', ['key' => $key]));

        $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.fetching', ['key' => $key]), WorkflowChannel::Jira);

        try {
            $issue = $this->provider->getIssue($key);
        } catch (ApiException $e) {
            $this->recorder->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('item.start.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return $this->recorder->toResponse(1);
        }

        $projectConfig = $this->gitRepository->readProjectConfig();

        // Handle Jira transition if enabled
        if (! empty($this->jiraConfig['JIRA_TRANSITION_ENABLED'])) {
            $this->handleTransition($key, $issue, $projectConfig);
            // All error handling is done inside handleTransition with warnings/errors
            // Branch creation continues regardless of transition success/failure
        }

        $prefix = $this->resolveBranchPrefix($issue, $projectConfig);
        // Use Symfony's AsciiSlugger to create a clean, lowercase slug for the branch name
        $slugger = new AsciiSlugger();
        $slugValue = $slugger->slug($issue->title)->lower()->toString();
        $branchName = "{$prefix}/{$key}-{$slugValue}";

        $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.generated_branch', ['branch' => $branchName]), WorkflowChannel::Git);

        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.fetching_changes'), WorkflowChannel::Git);
        $this->gitRepository->fetch();

        // Check for existing branches before creating
        $existingBranches = $this->gitBranchService->findBranchesByIssueKey($key);
        $branchAction = $this->determineBranchAction($branchName, $existingBranches);

        $this->executeBranchAction($branchAction, $branchName);

        return $this->recorder->toResponse(0);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function handleTransition(string $key, WorkItem $issue, array $projectConfig): void
    {
        $this->tryAssignIssueToCurrentUser($key);
        $projectKey = $this->gitRepository->getProjectKeyFromIssueKey($key);
        $transitionId = $this->resolveTransitionId($key, $projectKey, $projectConfig);
        if ($transitionId !== null) {
            $this->executeTransitionWithLogging($key, $transitionId);
        }
    }

    protected function tryAssignIssueToCurrentUser(string $key): void
    {
        try {
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.assigning', ['key' => $key]), WorkflowChannel::Jira);
            $this->provider->assign($key);
        } catch (ApiException $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.assign_error', ['error' => $e->getMessage()]));
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.assign_error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function resolveTransitionId(string $key, string $projectKey, array $projectConfig): ?int
    {
        if (isset($projectConfig['projectKey']) && $projectConfig['projectKey'] === $projectKey && isset($projectConfig['transitionId'])) {
            $id = (int) $projectConfig['transitionId'];
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.using_cached_transition', ['id' => $id]), WorkflowChannel::Jira);

            return $id;
        }

        return $this->promptForTransitionId($key, $projectKey);
    }

    protected function promptForTransitionId(string $key, string $projectKey): ?int
    {
        try {
            $transitions = $this->provider->listItemStateChanges($key);
            if ($transitions === []) {
                $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.no_transitions', ['key' => $key]));

                return null;
            }
            $options = array_map(fn (StateChange $t) => "{$t->name} (ID: {$t->id})", $transitions);
            $selected = $this->prompt->choice(MessageRef::key('item.start.select_transition'), $options);
            preg_match('/ID: (\d+)\)$/', $selected, $matches);
            if (! isset($matches[1])) {
                throw new \RuntimeException('Unable to extract transition ID from selection');
            }
            $transitionId = (int) $matches[1];
            if ($this->prompt->confirm(MessageRef::key('item.start.save_transition', ['project' => $projectKey]), true)) {
                $this->gitRepository->writeProjectConfig(['projectKey' => $projectKey, 'transitionId' => $transitionId]);
                $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.transition_saved', ['project' => $projectKey]), WorkflowChannel::Jira);
            }

            return $transitionId;
        } catch (ApiException $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_error', ['error' => $e->getMessage()]));
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);

            return null;
        } catch (\Exception $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_error', ['error' => $e->getMessage()]));

            return null;
        }
    }

    protected function executeTransitionWithLogging(string $key, int $transitionId): void
    {
        try {
            $this->provider->applyStateChange($key, (string) $transitionId);
            $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_success', ['key' => $key]));
        } catch (ApiException $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_exec_error', ['error' => $e->getMessage()]));
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.transition_exec_error', ['error' => $e->getMessage()]));
        }
    }

    protected function getBranchPrefixFromIssueType(string $issueType): string
    {
        return BranchNameGenerator::prefixForIssueType($issueType);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function resolveBranchPrefix(WorkItem $issue, array $projectConfig): string
    {
        if (! $this->usesLinearBranchPrefixMapping($projectConfig)) {
            return $this->getBranchPrefixFromIssueType($issue->issueType);
        }

        $teamKey = isset($projectConfig[ProjectStudConfigKeys::PROJECT_KEY]) && is_string($projectConfig[ProjectStudConfigKeys::PROJECT_KEY])
            ? $projectConfig[ProjectStudConfigKeys::PROJECT_KEY]
            : $this->gitRepository->getProjectKeyFromIssueKey($issue->key);

        $result = $this->linearTypeLabelResolver->resolveBranchPrefix(array_values($issue->labels), $projectConfig, $teamKey);
        if ($result['warning'] !== null) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, $result['warning']);
        }
        if ($result['matchedLabel'] !== null) {
            $this->recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('item.start.linear_type_label_matched', [
                    'label' => $result['matchedLabel'],
                    'prefix' => $result['prefix'],
                ]),
            );
        }

        return $result['prefix'];
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function usesLinearBranchPrefixMapping(array $projectConfig): bool
    {
        $provider = $projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER] ?? null;
        if (! is_string($provider)) {
            return false;
        }

        return strtolower(trim($provider)) === 'linear';
    }

    /**
     * Executes the determined branch action.
     *
     * @param array{action: string, branch: string} $branchAction
     */
    protected function executeBranchAction(array $branchAction, string $defaultBranchName): void
    {
        if ($branchAction['action'] === BranchAction::SWITCH_LOCAL) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.switching_branch', ['branch' => $branchAction['branch']]), WorkflowChannel::Git);
            $this->gitBranchService->switchBranch($branchAction['branch']);
            $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        if ($branchAction['action'] === BranchAction::SWITCH_REMOTE) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.switching_remote_branch', ['branch' => $branchAction['branch']]), WorkflowChannel::Git);
            $this->gitBranchService->switchToRemoteBranch($branchAction['branch']);
            $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        // Default: create new branch from the most advanced base ref
        $resolvedBase = $this->gitBranchService->resolveLatestBaseBranch($this->baseBranch);
        if ($resolvedBase !== $this->baseBranch) {
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.using_advanced_base', ['configured' => $this->baseBranch, 'resolved' => $resolvedBase]), WorkflowChannel::Git);
        }
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.creating_branch', ['branch' => $defaultBranchName]), WorkflowChannel::Git);
        $this->gitRepository->createBranch($defaultBranchName, $resolvedBase);
        $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.success', ['branch' => $defaultBranchName, 'base' => $resolvedBase]));
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
