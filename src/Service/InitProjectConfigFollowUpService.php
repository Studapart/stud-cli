<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OutputFormat;
use App\Handler\ConfigProjectInitHandler;
use App\Responder\ConfigProjectInitResponder;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * After global `config:init` saves, guides users toward `.git/stud.config` when needed
 * and optionally chains into the same logic as `stud config:project-init`.
 */
class InitProjectConfigFollowUpService
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ProjectStudConfigAdequacyChecker $adequacyChecker,
        private readonly ConfigProjectInitHandler $projectInitHandler,
        private readonly ConfigProjectInitResponder $projectInitResponder,
        private readonly TranslationService $translator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Runs only in CLI wizard mode (`config:init` without `--agent`).
     *
     * @param bool $isInteractiveCli From Castor/Symfony input (whether prompts are allowed).
     */
    public function runAfterGlobalSave(SymfonyStyle $io, bool $isAgentMode, bool $isInteractiveCli): void
    {
        if ($isAgentMode) {
            return;
        }

        if (! $this->isInsideGitRepository()) {
            $this->emitRunLaterHint();

            return;
        }

        $projectConfig = $this->gitRepository->readProjectConfig();
        if ($this->adequacyChecker->isAdequate($projectConfig)) {
            return;
        }

        if ($isInteractiveCli) {
            $confirmed = $this->logger->confirm(
                $this->translator->trans('config.init.project_follow_up.prompt_configure_now'),
                false
            );
            if ($confirmed) {
                $this->runInteractiveProjectInit($io);

                return;
            }
        }

        $this->emitRunLaterHint();
    }

    private function isInsideGitRepository(): bool
    {
        try {
            $this->gitRepository->getProjectConfigPath();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function emitRunLaterHint(): void
    {
        $this->logger->note(
            Logger::VERBOSITY_NORMAL,
            $this->translator->trans('config.init.project_follow_up.hint_run_later')
        );
    }

    private function runInteractiveProjectInit(SymfonyStyle $io): void
    {
        $response = $this->projectInitHandler->handle([], [], false, true, false);
        $this->projectInitResponder->respond($io, $response, OutputFormat::Cli);
        if (! $response->isSuccess()) {
            // @codeCoverageIgnoreStart
            // Process exit cannot be asserted in unit tests without a separate process.
            exit(1);
            // @codeCoverageIgnoreEnd
        }
    }
}
