<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\WorkflowOutput;

class ItemTransitionHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        mixed $_translator,
        private readonly WorkflowOutput $logger
    ) {
        unset($_translator);
    }

    public function handle(?string $key = null): int
    {
        $this->logger->addSection(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.section'));

        $resolvedKey = $this->resolveKey($key);
        if ($resolvedKey === null) {
            return 1;
        }
        if (! $this->verifyIssueExists($resolvedKey)) {
            return 1;
        }
        $transitions = $this->fetchTransitionsOrFail($resolvedKey);
        if ($transitions === null) {
            return 1;
        }
        $transitionId = $this->selectTransitionIdFromUser($transitions);
        if ($transitionId === null) {
            return 1;
        }

        return $this->executeTransitionAndReturn($resolvedKey, $transitionId);
    }

    protected function verifyIssueExists(string $key): bool
    {
        try {
            $this->logger->addJiraLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('item.transition.fetching', ['key' => $key]));
            $this->jiraService->getIssue($key);

            return true;
        } catch (ApiException $e) {
            $this->logger->addErrorWithDetails(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_not_found', ['key' => $key]), $e->getTechnicalDetails());

            return false;
        } catch (\Exception $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_not_found', ['key' => $key]));

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchTransitionsOrFail(string $key): ?array
    {
        try {
            $transitions = $this->jiraService->getTransitions($key);
            if ($transitions === []) {
                $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.no_transitions', ['key' => $key]));

                return null;
            }

            return $transitions;
        } catch (ApiException $e) {
            $this->logger->addErrorWithDetails(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_fetch', ['error' => $e->getMessage()]), $e->getTechnicalDetails());

            return null;
        } catch (\Exception $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_fetch', ['error' => $e->getMessage()]));

            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $transitions
     */
    protected function selectTransitionIdFromUser(array $transitions): ?int
    {
        $options = array_map(fn (array $t) => "{$t['name']} (ID: {$t['id']})", $transitions);
        $selected = $this->logger->choice(MessageRef::key('item.transition.select_transition'), $options);
        preg_match('/ID: (\d+)\)$/', $selected, $matches);
        if (! isset($matches[1])) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_fetch', ['error' => 'Unable to extract transition ID from selection']));

            return null;
        }

        return (int) $matches[1];
    }

    protected function executeTransitionAndReturn(string $key, int $transitionId): int
    {
        try {
            $this->jiraService->transitionIssue($key, $transitionId);
            $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.success', ['key' => $key]));

            return 0;
        } catch (ApiException $e) {
            $this->logger->addErrorWithDetails(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_execute', ['error' => $e->getMessage()]), $e->getTechnicalDetails());

            return 1;
        } catch (\Exception $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_execute', ['error' => $e->getMessage()]));

            return 1;
        }
    }

    protected function resolveKey(?string $key): ?string
    {
        // If key is provided, use it
        if ($key !== null) {
            return strtoupper($key);
        }

        // Try to detect from branch
        $detectedKey = $this->gitRepository->getJiraKeyFromBranchName();
        if ($detectedKey !== null) {
            $confirmed = $this->logger->confirm(
                MessageRef::key('item.transition.detected_key', ['key' => $detectedKey]),
                true
            );

            if ($confirmed) {
                return $detectedKey;
            }
        }

        // Prompt user for key
        $promptedKey = $this->logger->ask(MessageRef::key('item.transition.prompt_key'));
        if ($promptedKey === '' || $promptedKey === null) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_invalid_key'));

            return null;
        }

        $promptedKey = strtoupper(trim($promptedKey));

        // Validate key format
        if (! preg_match('/^[A-Z]+-\d+$/', $promptedKey)) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('item.transition.error_invalid_key'));

            return null;
        }

        return $promptedKey;
    }
}
