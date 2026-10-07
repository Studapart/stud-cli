<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\SubmitOptions;
use App\Response\CommandResponse;
use App\Response\WorkflowResponse;

/**
 * Runs optional flatten or the push phase, then opens or updates the pull request.
 *
 * A failed flatten or push phase is returned as-is so the caller does not create or update a PR.
 */
class SubmitDelivery
{
    public function __construct(
        private readonly FlattenHandler $flattenHandler,
        private readonly SubmitHandler $submitHandler,
    ) {
    }

    /**
     * @param (callable(): CommandResponse)|null $pushPhase Commit-and-push phase, already including flatten when requested
     */
    public function handle(bool $flatten, ?callable $pushPhase, SubmitOptions $options): WorkflowResponse|CommandResponse
    {
        $prepared = $this->prepare($flatten, $pushPhase);
        if ($prepared instanceof CommandResponse && ! $prepared->isSuccess()) {
            return $prepared;
        }

        $extra = $prepared instanceof CommandResponse ? $prepared->getMessages() : [];
        $workflow = $this->submitHandler->handle($options);

        return $extra === [] ? $workflow : $workflow->withAdditionalMessages($extra);
    }

    /**
     * @param (callable(): CommandResponse)|null $pushPhase
     */
    private function prepare(bool $flatten, ?callable $pushPhase): ?CommandResponse
    {
        if ($pushPhase !== null) {
            $push = $pushPhase();
            if (! $push->isSuccess()) {
                return $push;
            }

            return $flatten ? CommandResponse::success(messages: $push->getMessages()) : null;
        }

        if (! $flatten) {
            return null;
        }

        return $this->flattenHandler->handle();
    }
}
