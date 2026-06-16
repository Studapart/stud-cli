<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

class CommitUndoHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly PromptInterface $prompt,
        mixed $_translator
    ) {
        unset($_translator);
    }

    public function handle(mixed $quiet = false, mixed $legacyQuiet = false): CommandResponse
    {
        if ($quiet instanceof \Symfony\Component\Console\Style\SymfonyStyle) {
            $quiet = (bool) $legacyQuiet;
        }
        $quiet = (bool) $quiet;

        try {
            $this->gitRepository->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            $error = MessageRef::key('commit_undo.error_not_repo');

            return CommandResponse::error(
                $error,
                [ResponseMessage::error($error, $e->getMessage())],
            );
        }

        if (! $this->gitRepository->hasAtLeastOneCommit()) {
            return CommandResponse::error(MessageRef::key('commit_undo.error_no_commit'));
        }

        $messages = [];
        if ($this->gitRepository->isHeadPushed()) {
            $warning = MessageRef::key('commit_undo.warning_pushed');
            $messages[] = ResponseMessage::warning($warning);

            if (! $quiet) {
                $confirmed = $this->prompt->confirm(
                    MessageRef::key('commit_undo.confirm_continue'),
                    false
                );

                if (! $confirmed) {
                    return CommandResponse::error($warning, $messages);
                }
            }
        }

        $this->gitRepository->undoLastCommit();

        return CommandResponse::success(MessageRef::key('commit_undo.success'), messages: $messages);
    }
}
