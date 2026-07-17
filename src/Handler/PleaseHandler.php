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

    public function handle(): CommandResponse|int
    {
        $upstream = $this->gitRepository->getUpstreamBranch();

        if (null === $upstream) {
            return CommandResponse::error(MessageRef::key('please.error_no_upstream'));
        }

        $this->gitRepository->forcePushWithLease();

        return CommandResponse::success(
            MessageRef::key('push.success'),
            messages: [ResponseMessage::warning(MessageRef::key('please.warning_force'))],
        );
    }
}
