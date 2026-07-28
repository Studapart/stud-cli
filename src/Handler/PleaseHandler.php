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
    ) {
        unset($_translator);
    }

    /**
     * Force-push with lease when upstream exists; otherwise set upstream and push.
     *
     * @param bool $quiet When true (agent / quiet push fallback), omit the upstream-set notice
     */
    public function handle(bool $quiet = false): CommandResponse|int
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
