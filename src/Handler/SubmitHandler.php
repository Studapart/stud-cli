<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\PullRequestData;
use App\DTO\SubmitOptions;
use App\DTO\WorkflowRecorder;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Exception\PullRequestAssignmentException;
use App\Exception\StudConfigException;
use App\Guard\Capability\GitHosting\GithubAware;
use App\Guard\Capability\GitHosting\GitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Response\WorkflowResponse;
use App\Service\GitHostingPort;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\Prompt\PromptInterface;
use App\Service\SubmitLabelResolver;
use App\Service\SubmitPrBodyBuilder;

class SubmitHandler implements GithubAware, GitlabAware, GitRepositoryAware, ProjectBaseBranchAware, JiraAware
{
    private ?WorkflowEntryRecorder $recorder = null;

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly IssueTrackerPort $provider,
        private readonly ?GitHostingPort $githubProvider,
        private readonly SubmitPrBodyBuilder $prBodyBuilder,
        private readonly string $baseBranch,
        private readonly mixed $translator,
        private readonly PromptInterface $prompt,
    ) {
    }

    private function recorder(): WorkflowEntryRecorder
    {
        return $this->recorder ??= new WorkflowRecorder();
    }

    public function handle(SubmitOptions $options = new SubmitOptions()): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $this->recorder()->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.section'));

        $preflight = $this->runSubmitPreflight();
        if ($preflight['exitCode'] !== 0) {
            return $this->recorder()->toResponse($preflight['exitCode']);
        }
        /** @var array{exitCode: 0, branch: string, jiraKey: string, prTitle: string} $preflight */
        $branch = $preflight['branch'];
        $jiraKey = $preflight['jiraKey'];
        $prTitle = $preflight['prTitle'];

        $prBody = $this->buildPrBody($jiraKey);

        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = ($remoteOwner !== null && $remoteOwner !== '') ? "{$remoteOwner}:{$branch}" : $branch;
        $this->recorder()->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('submit.using_head', ['head' => $headBranch]), WorkflowChannel::Git);

        $finalLabels = $this->resolveLabels($options->labels, $options->quiet);
        if ($finalLabels === null) {
            return $this->recorder()->toResponse(1);
        }

        return $this->recorder()->toResponse($this->createPullRequest($prTitle, $headBranch, $prBody, $options, $finalLabels));
    }

    /**
     * Run preflight checks: optional dirty-tree note, branch, push, first commit, Jira key.
     *
     * @return array{exitCode: int, branch?: string, jiraKey?: string, prTitle?: string}
     */
    protected function runSubmitPreflight(): array
    {
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            $this->recorder()->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.note_dirty_working'));
        }

        $branch = $this->gitRepository->getCurrentBranchName();
        $baseBranchName = str_replace('origin/', '', $this->baseBranch);
        if ($branch === $baseBranchName || in_array($branch, ['main', 'master'], true)) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_base_branch'));

            return ['exitCode' => 1];
        }

        $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.pushing', ['branch' => $branch]), WorkflowChannel::Git);
        $pushProcess = $this->gitRepository->pushHeadToOrigin();
        if (! $pushProcess->isSuccessful()) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_push'));

            return ['exitCode' => 1];
        }

        $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.finding_commit'), WorkflowChannel::Git);
        $ancestorSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $firstCommitSha = $this->gitRepository->findFirstLogicalSha($ancestorSha);
        if ($firstCommitSha === null) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_no_logical'));

            return ['exitCode' => 1];
        }

        $firstLogicalMessage = $this->gitRepository->getCommitMessage($firstCommitSha);
        $jiraKey = $this->resolveJiraKey($firstLogicalMessage);
        if ($jiraKey === null) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_no_jira_key'));

            return ['exitCode' => 1];
        }

        return [
            'exitCode' => 0,
            'branch' => $branch,
            'jiraKey' => $jiraKey,
            'prTitle' => $this->extractPrTitleFromCommitMessage($firstLogicalMessage),
        ];
    }

    /**
     * Derive a single-line PR title from a (possibly multiline) commit message.
     * Returns the first non-empty line, trimmed; falls back to the trimmed message.
     */
    protected function extractPrTitleFromCommitMessage(string $commitMessage): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $commitMessage) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return trim($commitMessage);
    }

    private function resolveJiraKey(string $commitMessage): ?string
    {
        $jiraKey = $this->gitRepository->getIssueKeyFromBranchName();
        if ($jiraKey === null || $jiraKey === '') {
            preg_match('/(?i)\[([a-z]+-\d+)]/', $commitMessage, $matches);
            $jiraKey = $matches[1] ?? null;
        }

        return $jiraKey;
    }

    /**
     * Build PR body: fetch work-item description, convert HTML when needed, prepend issue link.
     */
    protected function buildPrBody(string $issueKey): string
    {
        return $this->prBodyBuilder->build($issueKey, $this->fetchIssueForPrBody($issueKey), $this->recorder());
    }

    protected function fetchIssueForPrBody(string $issueKey): ?WorkItem
    {
        try {
            $this->recorder()->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('submit.fetching_issue', ['key' => $issueKey]),
                WorkflowChannel::Jira,
            );

            return $this->provider->getIssue($issueKey, true);
        } catch (ApiException $e) {
            $hint = $e->getResolutionHint();
            if ($hint !== null) {
                $this->recorder()->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, $hint);
            } else {
                $this->recorder()->addWarning(
                    WorkflowEntryRecorder::VERBOSITY_NORMAL,
                    MessageRef::key('submit.warning_issue_fetch', ['error' => $e->getMessage()]),
                );
            }
            $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);

            return null;
        } catch (\Exception $e) {
            $this->recorder()->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('submit.warning_issue_fetch', ['error' => $e->getMessage()]),
            );

            return null;
        }
    }

    /**
     * Resolve labels: empty array if none; validate/process if provided and provider exists.
     *
     * @return array<string>|null null if user chose retry
     */
    protected function resolveLabels(?string $labels, bool $quiet): ?array
    {
        if ($labels === null || ! $this->githubProvider) {
            return [];
        }

        return $this->validateAndProcessLabels($labels, $quiet);
    }

    /**
     * Create PR via provider; handle 422 "already exists" by updating existing PR.
     *
     * @param array<string> $finalLabels
     */
    protected function createPullRequest(string $prTitle, string $headBranch, string $prBody, SubmitOptions $options, array $finalLabels): int
    {
        $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.creating'), WorkflowChannel::Git);

        try {
            if (! $this->githubProvider) {
                $this->recorder()->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.warning_no_provider'));

                return 0;
            }

            $baseBranchName = str_replace('origin/', '', $this->baseBranch);
            $prRequestData = new PullRequestData($prTitle, $headBranch, $baseBranchName, $prBody, $options->draft, $options->assignToAuthor);
            $prData = $this->githubProvider->createPullRequest($prRequestData);

            if (! empty($finalLabels)) {
                $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.adding_labels'), WorkflowChannel::Git);
                $this->githubProvider->addLabelsToPullRequest($prData['number'], $finalLabels);
            }
            if (isset($prData['number'])) {
                $this->recorder()->setPullNumber((int) $prData['number']);
            }
            $this->recorder()->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.success_created', ['url' => $prData['html_url']]));

            return 0;
        } catch (PullRequestAssignmentException $e) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_assign_author', [
                'url' => $e->getPullRequestUrl(),
                'error' => $e->getMessage(),
            ]));

            return 1;
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 422 && str_contains(strtolower($e->getTechnicalDetails()), 'pull request already exists')) {
                return $this->handleExistingPr($headBranch, $options, $finalLabels);
            }
            $this->recorder()->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('submit.error_create_pr', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return 1;
        } catch (\Exception $e) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_create_pr', ['error' => $e->getMessage()]));

            return 1;
        }
    }

    /**
     * When PR already exists: find it, apply labels and draft update, log success.
     *
     * @param array<string> $finalLabels
     */
    protected function handleExistingPr(string $headBranch, SubmitOptions $options, array $finalLabels): int
    {
        $this->recorder()->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.note_pr_exists'));
        if (! $this->githubProvider) {
            $this->recorder()->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.success_pushed'));

            return 0;
        }

        try {
            $existingPr = $this->githubProvider->findPullRequestByBranch($headBranch);
        } catch (\Exception $findError) {
            $this->recorder()->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, "  <fg=gray>Could not find existing PR: {$findError->getMessage()}</>", WorkflowChannel::Git);
            $this->recorder()->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.success_pushed'));

            return 0;
        }

        if ($existingPr === null) {
            $this->recorder()->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.success_pushed'));

            return 0;
        }

        return $this->applyUpdatesToExistingPr($existingPr, $options, $finalLabels);
    }

    /**
     * Apply labels/draft/assignment to an existing PR and record its number on success.
     *
     * @param array<string, mixed> $existingPr
     * @param array<string> $finalLabels
     */
    protected function applyUpdatesToExistingPr(array $existingPr, SubmitOptions $options, array $finalLabels): int
    {
        $prNumber = (int) $existingPr['number'];
        $this->applyLabelsToExistingPr($prNumber, $finalLabels);
        $this->maybeMarkExistingPrDraft($prNumber, $existingPr, $options);
        if ($options->assignToAuthor && ! $this->assignExistingPrToAuthor($existingPr)) {
            return 1;
        }
        $this->recorder()->setPullNumber($prNumber);
        $this->recorder()->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.success_pushed'));

        return 0;
    }

    /**
     * @param array<string> $finalLabels
     */
    protected function applyLabelsToExistingPr(int $prNumber, array $finalLabels): void
    {
        if ($finalLabels === []) {
            return;
        }

        $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.adding_labels'), WorkflowChannel::Git);

        try {
            $this->githubProvider->addLabelsToPullRequest($prNumber, $finalLabels);
        } catch (\Exception $labelError) {
            $this->recorder()->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_add_labels', ['error' => $labelError->getMessage()]));
        }
    }

    /**
     * @param array<string, mixed> $existingPr
     */
    protected function maybeMarkExistingPrDraft(int $prNumber, array $existingPr, SubmitOptions $options): void
    {
        if (! $options->draft || $existingPr['draft']) {
            return;
        }

        $this->recorder()->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.updating_to_draft'));

        try {
            $this->githubProvider->updatePullRequest($prNumber, true);
        } catch (\Exception $draftError) {
            $this->recorder()->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_update_draft', ['error' => $draftError->getMessage()]));
        }
    }

    /**
     * @param array<string, mixed> $existingPr
     */
    protected function assignExistingPrToAuthor(array $existingPr): bool
    {
        try {
            $this->githubProvider->assignPullRequestToAuthor($existingPr);

            return true;
        } catch (\Throwable $assignmentError) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_assign_author', [
                'url' => (string) ($existingPr['html_url'] ?? $existingPr['web_url'] ?? ''),
                'error' => $assignmentError->getMessage(),
            ]));

            return false;
        }
    }

    /**
     * Validates and processes labels, handling unknown labels interactively.
     *
     * @return array<string>|null Array of final labels to apply, or null if user chose to retry
     */
    protected function validateAndProcessLabels(string $labelsInput, bool $quiet = false): ?array
    {
        return $this->createLabelResolver()->validateAndProcessLabels($this->recorder(), $labelsInput, $quiet);
    }

    protected function createLabelResolver(): SubmitLabelResolver
    {
        if (! $this->githubProvider) {
            throw StudConfigException::gitProviderNotConfigured();
        }

        return new SubmitLabelResolver($this->githubProvider, $this->translator, $this->prompt);
    }
}
