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
     * Squash fixup commits. Standalone flatten keeps the force-push hint.
     *
     * Delivery commands pass false and add their own publication warning.
     */
    public function handle(bool $includePleaseHint = true): CommandResponse
    {
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            return CommandResponse::error(MessageRef::key('flatten.error_dirty_working'));
        }

        $baseSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        if (! $this->gitRepository->hasFixupCommits($baseSha)) {
            return CommandResponse::success(
                data: ['rewritten' => false],
                messages: [ResponseMessage::notice(MessageRef::key('flatten.no_fixups'))],
            );
        }

        return $this->rebase($baseSha, $includePleaseHint);
    }

    /**
     * Optional delivery path. A dirty tree or a host that did not create a commit skips rebase.
     */
    public function applyWhenSafe(CommandResponse $host, bool $requireCreatedCommit): CommandResponse
    {
        if (! $host->isSuccess()) {
            return $host;
        }
        if (! $this->canRewrite($host, $requireCreatedCommit)) {
            return $this->skipped($host);
        }

        return $this->mergeIntoHost($host, $this->handle(false));
    }

    private function canRewrite(CommandResponse $host, bool $requireCreatedCommit): bool
    {
        if (! empty($this->gitRepository->getPorcelainStatus())) {
            return false;
        }
        if (! $requireCreatedCommit) {
            return true;
        }

        return isset($host->data['commitMessage']) || isset($host->data['fixupSha']);
    }

    private function skipped(CommandResponse $host): CommandResponse
    {
        $messages = $host->getMessages();
        $messages[] = ResponseMessage::notice(MessageRef::key('flatten.notice_skipped'));
        $data = $host->data;
        $data['rewritten'] = false;

        return CommandResponse::success($host->message, $data, $messages);
    }

    private function mergeIntoHost(CommandResponse $host, CommandResponse $flat): CommandResponse
    {
        $messages = array_merge($host->getMessages(), $flat->getMessages());
        if (! $flat->isSuccess()) {
            $data = $host->payloadData();
            $data['rewritten'] = false;

            return CommandResponse::error(
                $flat->getErrorMessage() ?? MessageRef::key('flatten.error_rebase', ['error' => 'flatten failed']),
                $messages,
                $data,
            );
        }
        $data = $host->data;
        $data['rewritten'] = ($flat->data['rewritten'] ?? false) === true;

        return CommandResponse::success($host->message, $data, $messages);
    }

    private function rebase(string $baseSha, bool $includePleaseHint): CommandResponse
    {
        $messages = [];
        if ($includePleaseHint) {
            $messages[] = ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite'));
        }

        try {
            $this->gitRepository->rebaseAutosquash($baseSha);

            return CommandResponse::success(MessageRef::key('flatten.success'), ['rewritten' => true], $messages);
        } catch (GitException $e) {
            $error = MessageRef::key('flatten.error_rebase', ['error' => $e->getMessage()]);

            return CommandResponse::error(
                $error,
                [ResponseMessage::error($error, $e->getTechnicalDetails())],
                ['rewritten' => false],
            );
        } catch (\Exception $e) {
            return CommandResponse::error(
                MessageRef::key('flatten.error_rebase', ['error' => $e->getMessage()]),
                [],
                ['rewritten' => false],
            );
        }
    }
}
