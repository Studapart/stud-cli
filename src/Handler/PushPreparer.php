<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use App\Service\GitRepository;

final class PushPreparer
{
    public function __construct(
        private readonly CommitHandler $commitHandler,
        private readonly GitRepository $gitRepository,
        private readonly FlattenHandler $flattenHandler,
    ) {
    }

    /**
     * Commit when stageAll or staged files exist; otherwise soft-skip (dirty-tree notice if needed).
     */
    public function commit(bool $isNew, ?string $message, bool $stageAll, bool $quiet): CommandResponse
    {
        if (! $stageAll && ! $this->gitRepository->hasStagedChanges()) {
            return $this->skippedCommitResponse();
        }

        $response = $this->commitHandler->handle($isNew, $message, $stageAll, $quiet);
        if ($response instanceof CommandResponse) {
            return $response;
        }

        return CommandResponse::fromExitCode($response, MessageRef::key('push.commit_created'), MessageRef::key('push.commit_failed'));
    }

    public function flatten(CommandResponse $commitResponse, bool $flatten): CommandResponse
    {
        if (! $flatten) {
            return $commitResponse;
        }

        return $this->withPublishWarning($this->flattenHandler->applyWhenSafe($commitResponse, true));
    }

    /**
     * @return CommandResponse Success with optional dirty-tree notice
     */
    private function skippedCommitResponse(): CommandResponse
    {
        $messages = [];
        if (trim($this->gitRepository->getPorcelainStatus()) !== '') {
            $messages[] = ResponseMessage::notice(MessageRef::key('push.note_uncommitted_left'));
        }

        return CommandResponse::success(messages: $messages);
    }

    private function withPublishWarning(CommandResponse $response): CommandResponse
    {
        if (! $response->isSuccess() || ($response->data['rewritten'] ?? false) !== true) {
            return $response;
        }
        $messages = $response->getMessages();
        $messages[] = ResponseMessage::warning(MessageRef::key('flatten.warning_published'));

        return CommandResponse::success($response->message, $response->data, $messages);
    }
}
