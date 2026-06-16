<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Enum\OutputFormat;
use App\Handler\ConfigProjectInitHandler;
use App\Responder\ConfigProjectInitResponder;
use App\Response\WorkflowResponse;
use App\Service\Prompt\PromptInterface;
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
        mixed $translator,
        private readonly PromptInterface $prompt,
    ) {
        unset($translator);
    }

    /**
     * Runs only in CLI wizard mode (`config:init` without `--agent`).
     */
    public function augmentAfterGlobalSave(WorkflowResponse $response, bool $isInteractiveCli, SymfonyStyle $io): WorkflowResponse
    {
        $recorder = new WorkflowRecorder();
        $recorder->absorbResponse($response);

        if (! $this->isInsideGitRepository()) {
            $this->emitRunLaterHint($recorder);

            return $recorder->toResponse($response->exitCode);
        }

        $projectConfig = $this->gitRepository->readProjectConfig();
        if ($this->adequacyChecker->isAdequate($projectConfig)) {
            return $recorder->toResponse($response->exitCode);
        }

        if ($isInteractiveCli) {
            $confirmed = $this->prompt->confirm(
                MessageRef::key('config.init.project_follow_up.prompt_configure_now'),
                false
            );
            if ($confirmed) {
                $this->runInteractiveProjectInit($recorder, $io);

                return $recorder->toResponse($response->exitCode);
            }
        }

        $this->emitRunLaterHint($recorder);

        return $recorder->toResponse($response->exitCode);
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

    private function emitRunLaterHint(WorkflowEntryRecorder $recorder): void
    {
        $recorder->addNote(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('config.init.project_follow_up.hint_run_later')
        );
    }

    private function runInteractiveProjectInit(WorkflowEntryRecorder $recorder, SymfonyStyle $io): void
    {
        $initResponse = $this->projectInitHandler->handle([], [], false, true, false, $recorder);
        $this->projectInitResponder->respond($io, $initResponse, OutputFormat::Cli);
        if (! $initResponse->isSuccess()) {
            // @codeCoverageIgnoreStart
            // Process exit cannot be asserted in unit tests without a separate process.
            exit(1);
            // @codeCoverageIgnoreEnd
        }
    }
}
