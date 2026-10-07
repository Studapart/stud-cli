<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\GitRepositoryAware;
use App\Response\CommandResponse;
use App\Service\GitRepository;

class PleaseHandler implements GitRepositoryAware
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        mixed $_translator,
        private readonly FlattenHandler $flattenHandler,
    ) {
        unset($_translator);
    }

    /**
     * Force-push with lease when upstream exists; otherwise set upstream and push.
     *
     * When `$flatten` is true, the tree must be clean and fixups are autosquashed first.
     * A dirty tree or flatten failure does not push.
     *
     * @param bool $quiet When true (agent / quiet push fallback), omit the upstream-set notice
     */
    public function handle(bool $quiet = false, bool $flatten = false): CommandResponse
    {
        $flattenMessages = [];
        if ($flatten) {
            $flat = $this->flattenHandler->handle();
            if (! $flat->isSuccess()) {
                return $flat;
            }
            $flattenMessages = $flat->getMessages();
        }

        $result = $this->pushWithLease($quiet);
        if ($flattenMessages !== []) {
            return $result->withAdditionalMessages($flattenMessages);
        }

        return $result;
    }

    /**
     * Force-push with lease when upstream exists; otherwise set upstream and push.
     */
    private function pushWithLease(bool $quiet): CommandResponse
    {
        $upstream = $this->gitRepository->getUpstreamBranch();

        if (null === $upstream) {
            return $this->pushAndSetUpstream($quiet);
        }

        $this->gitRepository->forcePushWithLease();

        return CommandResponse::success(
            MessageRef::key('push.success'),
            messages: [ResponseMessage::warning(MessageRef::key('please.warning_force'))],
        );
    }

    protected function pushAndSetUpstream(bool $quiet): CommandResponse
    {
        $branch = $this->gitRepository->getCurrentBranchName();
        $process = $this->gitRepository->pushToOrigin($branch);
        if (! $process->isSuccessful()) {
            return CommandResponse::error(MessageRef::key('push.error_push'));
        }

        $messages = [];
        if (! $quiet) {
            $messages[] = ResponseMessage::notice(MessageRef::key('please.note_upstream_set'));
        }

        return CommandResponse::success(MessageRef::key('push.success'), messages: $messages);
    }
}
