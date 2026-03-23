<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class PushHandler
{
    public function __construct(
        private readonly CommitHandler $commitHandler,
        private readonly GitRepository $gitRepository,
        private readonly PleaseHandler $pleaseHandler,
        private readonly TranslationService $translator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Run commit (same semantics as stud commit), then push HEAD to origin. On a failed non-fast-forward
     * push, optionally delegates to {@see PleaseHandler} per quiet, agent, `--no-please` (CLI only), and
     * agent `pleaseFallback` (JSON / folded from CLI `--no-please` when using `--agent`).
     *
     * @param bool $pleaseFallback In agent mode: when false, disables the please fallback (the only agent control). Ignored when not in agent mode (pass true).
     */
    public function handle(
        SymfonyStyle $io,
        bool $isNew,
        ?string $message,
        bool $stageAll,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): int {
        $commitExit = $this->commitHandler->handle($io, $isNew, $message, $stageAll, $quiet);
        if ($commitExit !== 0) {
            return $commitExit;
        }

        $branch = $this->gitRepository->getCurrentBranchName();
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('push.pushing', ['branch' => $branch]));

        $pushProcess = $this->gitRepository->pushHeadToOrigin();
        if ($pushProcess->isSuccessful()) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('push.success'));

            return 0;
        }

        return $this->handleFailedPush($io, $quiet, $noPlease, $agentMode, $pleaseFallback);
    }

    /**
     * Handles a rejected normal push: error, prompt, or run please.
     */
    protected function handleFailedPush(
        SymfonyStyle $io,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): int {
        if ($agentMode) {
            if (! $pleaseFallback) {
                $this->emitPushFailedError();

                return 1;
            }

            return $this->pleaseHandler->handle($io);
        }

        if ($noPlease) {
            $this->emitPushFailedError();

            return 1;
        }

        $interactive = ! $quiet;
        if ($interactive) {
            $confirmed = $this->logger->confirm(
                $this->translator->trans('push.confirm_please'),
                false
            );
            if (! $confirmed) {
                $this->emitPushFailedError();

                return 1;
            }
        }

        return $this->pleaseHandler->handle($io);
    }

    protected function emitPushFailedError(): void
    {
        $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('push.error_push')));
    }
}
