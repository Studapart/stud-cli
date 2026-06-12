<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\WorkflowOutput;

class StatusHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        mixed $_translator,
        private readonly WorkflowOutput $logger
    ) {
        unset($_translator);
    }

    public function handle(): int
    {
        $this->logger->addSection(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('status.section'));
        $key = $this->gitRepository->getJiraKeyFromBranchName();
        $branch = $this->gitRepository->getCurrentBranchName();

        // Jira Status
        if ($key) {
            $this->logger->addJiraLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('status.fetching', ['key' => $key]));

            try {
                $issue = $this->jiraService->getIssue($key);
                $this->logger->addLine(WorkflowOutput::VERBOSITY_NORMAL, "Jira:   <fg=yellow>[{$issue->status}]</> {$issue->key}: {$issue->title}");
            } catch (ApiException $e) {
                $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('status.jira_error', ['error' => $e->getMessage()]));
                $this->logger->addText(WorkflowOutput::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
            } catch (\Exception $e) {
                $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('status.jira_error', ['error' => $e->getMessage()]));
            }
        } else {
            $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('status.jira_no_key'));
        }

        // Git Status
        $this->logger->addLine(WorkflowOutput::VERBOSITY_NORMAL, "Git:    {$branch}");

        // Local Status
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        $changeCount = count(array_filter(explode("\n", $gitStatus)));

        if ($changeCount > 0) {
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('status.local_changes', ['count' => $changeCount]));
        } else {
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('status.local_clean'));
        }

        return 0;
    }
}
