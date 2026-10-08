<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Exception\GitException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Response\CommandResponse;
use App\Service\GitRepository;

class FlattenHandler implements GitRepositoryAware, ProjectBaseBranchAware
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly string $baseBranch,
        mixed $_translator,
    ) {
        unset($_translator);
    }

    /**
     * Squash fixup commits. Standalone flatten keeps the please hint; delivery commands pass false.
     */
    public function handle(bool $includePleaseHint = true): CommandResponse
    {
        if ($this->gitRepository->getPorcelainStatus() !== '') {
            return CommandResponse::error(
                MessageRef::key('flatten.error_dirty_working'),
                data: ['rewritten' => false],
            );
        }

        $baseSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        if (! $this->gitRepository->hasFixupCommits($baseSha)) {
            return $this->noFixups();
        }

        return $this->rebase($baseSha, $includePleaseHint);
    }

    /**
     * Nothing to squash is a successful no-op.
     */
    private function noFixups(): CommandResponse
    {
        return CommandResponse::success(
            data: ['rewritten' => false],
            messages: [ResponseMessage::notice(MessageRef::key('flatten.no_fixups'))],
        );
    }

    /**
     * Autosquash onto the merge base. The please hint stays only for standalone flatten.
     */
    private function rebase(string $baseSha, bool $includePleaseHint): CommandResponse
    {
        $messages = $includePleaseHint
            ? [ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite'))]
            : [];

        try {
            $this->gitRepository->rebaseAutosquash($baseSha);

            return CommandResponse::success(
                MessageRef::key('flatten.success'),
                ['rewritten' => true],
                $messages,
            );
        } catch (GitException $e) {
            return $this->rebaseFailed($e->getMessage(), $e->getTechnicalDetails());
        } catch (\Exception $e) {
            return $this->rebaseFailed($e->getMessage(), null);
        }
    }

    /**
     * Git failures keep technical details. Other failures keep the error message only.
     */
    private function rebaseFailed(string $error, ?string $technicalDetails): CommandResponse
    {
        $message = MessageRef::key('flatten.error_rebase', ['error' => $error]);
        $messages = $technicalDetails === null
            ? []
            : [ResponseMessage::error($message, $technicalDetails)];

        return CommandResponse::error($message, $messages, ['rewritten' => false]);
    }
}
