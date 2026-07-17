<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Service\Prompt\PromptInterface;

/**
 * Interactive remediation for missing configuration keys detected by CommandGuard.
 */
class ConfigRemediationService
{
    public function __construct(
        private readonly WorkflowOutput $logger,
        mixed $translator,
        private readonly ?GitBranchService $gitBranchService = null,
        private readonly ?PromptInterface $prompt = null,
    ) {
        unset($translator);
    }

    /**
     * @param array<string> $missingKeys
     * @return array<string, mixed>
     */
    public function promptForMissingKeys(array $missingKeys, string $scope): array
    {
        $values = [];

        foreach ($missingKeys as $key) {
            $autoDetected = $this->autoDetectKey($key);
            if ($autoDetected !== null) {
                $this->logger->addNote(
                    WorkflowOutput::VERBOSITY_NORMAL,
                    MessageRef::key('config.auto_detected', ['key' => $key, 'value' => $autoDetected])
                );
                $values[$key] = $autoDetected;

                continue;
            }

            $value = $this->promptForKey($key, $scope);
            if ($value !== null && trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    protected function promptForKey(string $key, string $scope): ?string
    {
        if ($key === ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER) {
            return $this->promptIssueTrackerProvider();
        }

        $prompt = $this->buildPromptForKey($key, $scope);
        $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, $this->persistHintMessage($scope));

        return $this->logger->ask($prompt, null);
    }

    protected function promptIssueTrackerProvider(): ?string
    {
        if ($this->prompt === null) {
            return $this->logger->ask(
                MessageRef::key('config.missing_project_key_issue_tracker_provider'),
                IssueTrackerProvider::Auto->value,
            );
        }

        $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, $this->persistHintMessage('project'));
        $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('config.remediation.provider_alternative'));

        $choice = $this->prompt->choice(
            MessageRef::key('config.missing_project_key_issue_tracker_provider'),
            IssueTrackerProvider::projectConfigValues(),
            IssueTrackerProvider::Auto->value,
        );

        return is_string($choice) ? $choice : null;
    }

    protected function buildPromptForKey(string $key, string $scope): MessageRef
    {
        if ($scope === 'global') {
            return MessageRef::key('config.missing_global_key_detailed', ['key' => $key]);
        }

        return MessageRef::key('config.missing_project_key_detailed', ['key' => $key]);
    }

    protected function persistHintMessage(string $scope): MessageRef
    {
        return $scope === 'global'
            ? MessageRef::key('config.remediation.persist_hint_global')
            : MessageRef::key('config.remediation.persist_hint_project');
    }

    public function autoDetectKey(string $key): ?string
    {
        if ($key === 'baseBranch' && $this->gitBranchService !== null) {
            return $this->autoDetectBaseBranch();
        }

        return null;
    }

    protected function autoDetectBaseBranch(): ?string
    {
        try {
            $candidates = ['develop', 'main', 'master'];
            $remoteBranches = $this->gitBranchService->getAllRemoteBranches('origin');

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $remoteBranches, true)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
