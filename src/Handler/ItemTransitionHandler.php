<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTransitionHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, ?string $key = null): int
    {
        $io->section($this->translator->trans('item.transition.section'));

        $resolvedKey = $this->resolveKey($io, $key);
        if ($resolvedKey === null) {
            return 1;
        }

        // Verify issue exists
        try {
            if ($io->isVerbose()) {
                $io->writeln("  <fg=gray>{$this->translator->trans('item.transition.fetching', ['key' => $resolvedKey])}</>");
            }
            $this->jiraService->getIssue($resolvedKey);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.transition.error_not_found', ['key' => $resolvedKey]));

            return 1;
        }

        // Fetch transitions
        try {
            $transitions = $this->jiraService->getTransitions($resolvedKey);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.transition.error_fetch', ['error' => $e->getMessage()]));

            return 1;
        }

        if (empty($transitions)) {
            $io->warning($this->translator->trans('item.transition.no_transitions', ['key' => $resolvedKey]));

            return 1;
        }

        // Display transitions and prompt user
        $transitionOptions = [];
        foreach ($transitions as $transition) {
            $transitionOptions[] = "{$transition['name']} (ID: {$transition['id']})";
        }

        $selectedDisplay = $io->choice(
            $this->translator->trans('item.transition.select_transition'),
            $transitionOptions
        );

        // Extract transition ID from selection
        preg_match('/ID: (\d+)\)$/', $selectedDisplay, $matches);
        // SymfonyStyle::choice() validates input and only returns one of the provided options,
        // which all match our regex pattern, so this error case cannot occur in practice
        // @codeCoverageIgnoreStart
        if (! isset($matches[1])) {
            $io->error($this->translator->trans('item.transition.error_fetch', ['error' => 'Unable to extract transition ID from selection']));

            return 1;
        }
        // @codeCoverageIgnoreEnd

        $transitionId = (int) $matches[1];

        // Execute transition
        try {
            $this->jiraService->transitionIssue($resolvedKey, $transitionId);
            $io->success($this->translator->trans('item.transition.success', ['key' => $resolvedKey]));

            return 0;
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.transition.error_execute', ['error' => $e->getMessage()]));

            return 1;
        }
    }

    protected function resolveKey(SymfonyStyle $io, ?string $key): ?string
    {
        // If key is provided, use it
        if ($key !== null) {
            return strtoupper($key);
        }

        // Try to detect from branch
        $detectedKey = $this->gitRepository->getJiraKeyFromBranchName();
        if ($detectedKey !== null) {
            $confirmed = $io->confirm(
                $this->translator->trans('item.transition.detected_key', ['key' => $detectedKey]),
                true
            );

            if ($confirmed) {
                return $detectedKey;
            }
        }

        // Prompt user for key
        $promptedKey = $io->ask($this->translator->trans('item.transition.prompt_key'));
        if ($promptedKey === null || $promptedKey === '') {
            $io->error($this->translator->trans('item.transition.error_invalid_key'));

            return null;
        }

        $promptedKey = strtoupper(trim($promptedKey));

        // Validate key format
        if (! preg_match('/^[A-Z]+-\d+$/', $promptedKey)) {
            $io->error($this->translator->trans('item.transition.error_invalid_key'));

            return null;
        }

        return $promptedKey;
    }
}
