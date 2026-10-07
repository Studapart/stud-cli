<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\CommitDeliveryInput;
use App\Response\CommandResponse;

/**
 * Commits, then optionally runs {@see FlattenHandler}. Never pushes.
 */
class CommitDelivery
{
    public function __construct(
        private readonly CommitHandler $commitHandler,
        private readonly FlattenHandler $flattenHandler,
    ) {
    }

    /**
     * Commit first. When flatten is requested and the commit succeeded, autosquash before returning.
     */
    public function handle(CommitDeliveryInput $input): CommandResponse
    {
        $commit = $this->asCommand(
            $this->commitHandler->handle($input->isNew, $input->message, $input->stageAll, $input->quiet),
        );
        if (! $commit->isSuccess() || ! $input->flatten) {
            return $commit;
        }

        return $this->flattenAfterCommit($commit);
    }

    private function flattenAfterCommit(CommandResponse $commit): CommandResponse
    {
        $flat = $this->flattenHandler->handle();
        if (! $flat->isSuccess()) {
            return CommandResponse::error(
                $flat->getErrorMessage() ?? 'flatten failed',
                array_merge($commit->getMessages(), $flat->getMessages()),
            );
        }

        return $commit->withAdditionalMessages($flat->getMessages());
    }

    private function asCommand(CommandResponse|int $response): CommandResponse
    {
        if ($response instanceof CommandResponse) {
            return $response;
        }

        return CommandResponse::fromExitCode($response, 'Commit created', 'Commit failed');
    }
}
