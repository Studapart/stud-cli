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
     * Squash fixup commits on a clean tree.
     *
     * @param bool $includePleaseHint When true, warn that a later force-push may be required
     */
    public function handle(bool $includePleaseHint = true): CommandResponse
    {
        if ($this->workingTreeIsDirty()) {
            return CommandResponse::error(MessageRef::key('flatten.error_dirty_working'));
        }

        $baseSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        if (! $this->gitRepository->hasFixupCommits($baseSha)) {
            return CommandResponse::success(
                messages: [ResponseMessage::notice(MessageRef::key('flatten.no_fixups'))],
                data: ['rewritten' => false],
            );
        }

        try {
            $this->gitRepository->rebaseAutosquash($baseSha);
        } catch (GitException $e) {
            return $this->rebaseError(MessageRef::key('flatten.error_rebase', ['error' => $e->getMessage()]), $e->getTechnicalDetails());
        } catch (\Exception $e) {
            return $this->rebaseError(MessageRef::key('flatten.error_rebase', ['error' => $e->getMessage()]));
        }

        return CommandResponse::success(
            MessageRef::key('flatten.success'),
            ['rewritten' => true],
            [$this->rewriteWarning($includePleaseHint)],
        );
    }

    /**
     * Flatten only after a created commit on a clean tree. A skip keeps the commit result.
     *
     * Commit keeps the standalone please hint. Push and please pass false because they publish the rewrite.
     */
    public function applyToCommitResult(CommandResponse $commit, bool $includePleaseHint = false): CommandResponse
    {
        if (! $this->commitWasCreated($commit) || $this->workingTreeIsDirty()) {
            return $commit->withFlattenResult(false, [ResponseMessage::notice(MessageRef::key('flatten.notice_skipped'))]);
        }

        $flatten = $this->handle($includePleaseHint);
        $messages = array_merge($commit->getMessages(), $flatten->getMessages());
        $rewritten = (bool) ($flatten->data['rewritten'] ?? false);
        if (! $flatten->isSuccess()) {
            return CommandResponse::error(
                $flatten->getErrorMessage() ?? MessageRef::key('flatten.error_rebase', ['error' => 'flatten failed']),
                $messages,
                ['rewritten' => false] + $commit->data,
            );
        }

        return CommandResponse::success($commit->message, ['rewritten' => $rewritten] + $commit->data, $messages);
    }

    private function workingTreeIsDirty(): bool
    {
        return trim($this->gitRepository->getPorcelainStatus()) !== '';
    }

    private function commitWasCreated(CommandResponse $commit): bool
    {
        return isset($commit->data['commitMessage']) || isset($commit->data['fixupSha']);
    }

    private function rewriteWarning(bool $includePleaseHint): ResponseMessage
    {
        $key = $includePleaseHint ? 'flatten.warning_rewrite' : 'flatten.warning_published';

        return ResponseMessage::warning(MessageRef::key($key));
    }

    private function rebaseError(MessageRef $error, ?string $technicalDetails = null): CommandResponse
    {
        return CommandResponse::error(
            $error,
            [ResponseMessage::error($error, $technicalDetails)],
            ['rewritten' => false],
        );
    }
}
