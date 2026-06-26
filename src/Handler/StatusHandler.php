<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\WorkflowResponse;
use App\Service\GitRepository;
use App\Service\WorkItemProviderInterface;

class StatusHandler implements GitRepositoryAware, WorkItemJiraAware
{
    private WorkflowEntryRecorder $recorder;

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly WorkItemProviderInterface $provider,
        mixed $_translator,
    ) {
        unset($_translator);
    }

    public function handle(): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('status.section'));
        $key = $this->gitRepository->getJiraKeyFromBranchName();
        $branch = $this->gitRepository->getCurrentBranchName();

        if ($key) {
            $this->recordJiraStatus($key);
        } else {
            $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('status.jira_no_key'));
        }

        $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, "Git:    {$branch}");
        $this->recordLocalStatus();

        return $this->recorder->toResponse(0);
    }

    protected function recordJiraStatus(string $key): void
    {
        $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('status.fetching', ['key' => $key]), WorkflowChannel::Jira);

        try {
            $issue = $this->provider->getIssue($key);
            $this->recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                "Jira:   <fg=yellow>[{$issue->status}]</> {$issue->key}: {$issue->title}",
                WorkflowChannel::Jira,
            );
        } catch (ApiException $e) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('status.jira_error', ['error' => $e->getMessage()]));
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('status.jira_error', ['error' => $e->getMessage()]));
        }
    }

    protected function recordLocalStatus(): void
    {
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        $changeCount = count(array_filter(explode("\n", $gitStatus)));

        if ($changeCount > 0) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('status.local_changes', ['count' => $changeCount]));
        } else {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('status.local_clean'));
        }
    }
}
