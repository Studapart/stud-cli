<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTransitionHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, ?string $key = null): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.section'));

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
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.transition.fetching', ['key' => $key])}");
            $this->jiraService->getIssue($key);

            return true;
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_not_found', ['key' => $key]), $e->getTechnicalDetails());

            return false;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_not_found', ['key' => $key]));

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
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.no_transitions', ['key' => $key]));

                return null;
            }

            return $transitions;
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_fetch', ['error' => $e->getMessage()]), $e->getTechnicalDetails());

            return null;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_fetch', ['error' => $e->getMessage()]));

            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $transitions
     */
    protected function selectTransitionIdFromUser(array $transitions): ?int
    {
        $options = array_map(fn (array $t) => "{$t['name']} (ID: {$t['id']})", $transitions);
        $selected = $this->logger->choice($this->translator->trans('item.transition.select_transition'), $options);
        preg_match('/ID: (\d+)\)$/', $selected, $matches);
        if (! isset($matches[1])) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_fetch', ['error' => 'Unable to extract transition ID from selection']));

            return null;
        }

        return (int) $matches[1];
    }

    protected function executeTransitionAndReturn(string $key, int $transitionId): int
    {
        try {
            $this->jiraService->transitionIssue($key, $transitionId);
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.success', ['key' => $key]));

            return 0;
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_execute', ['error' => $e->getMessage()]), $e->getTechnicalDetails());

            return 1;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_execute', ['error' => $e->getMessage()]));

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
                $this->translator->trans('item.transition.detected_key', ['key' => $detectedKey]),
                true
            );

            if ($confirmed) {
                return $detectedKey;
            }
        }

        // Prompt user for key
        $promptedKey = $this->logger->ask($this->translator->trans('item.transition.prompt_key'));
        if ($promptedKey === '' || $promptedKey === null) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_invalid_key'));

            return null;
        }

        $promptedKey = strtoupper(trim($promptedKey));

        // Validate key format
        if (! preg_match('/^[A-Z]+-\d+$/', $promptedKey)) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.transition.error_invalid_key'));

            return null;
        }

        return $promptedKey;
    }
}
