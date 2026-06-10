<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

class PushHandler
{
    public function __construct(
        private readonly CommitHandler $commitHandler,
        private readonly GitRepository $gitRepository,
        private readonly PleaseHandler $pleaseHandler,
        mixed $_translator,
        private readonly PromptInterface $prompt,
    ) {
        unset($_translator);
    }

    /**
     * Run commit (same semantics as stud commit), then push HEAD to origin. On a failed non-fast-forward
     * push, optionally delegates to {@see PleaseHandler} per quiet, agent, `--no-please` (CLI only), and
     * agent `pleaseFallback` (JSON / folded from CLI `--no-please` when using `--agent`).
     */
    public function handle(
        mixed $first,
        mixed $second = null,
        mixed $third = null,
        mixed $fourth = false,
        mixed $fifth = false,
        mixed $sixth = false,
        mixed $seventh = false,
        mixed $eighth = true,
    ): CommandResponse {
        [$isNew, $message, $stageAll, $quiet, $noPlease, $agentMode, $pleaseFallback] = $this->normalizeHandleArguments(
            $first,
            $second,
            $third,
            $fourth,
            $fifth,
            $sixth,
            $seventh,
            $eighth,
        );
        $commitResponse = $this->normalizeResponse(
            $this->commitHandler->handle($isNew, $message, $stageAll, $quiet),
            'Commit created',
            'Commit failed',
        );
        if (! $commitResponse->isSuccess()) {
            return $commitResponse;
        }

        $branch = $this->gitRepository->getCurrentBranchName();

        $pushProcess = $this->gitRepository->pushHeadToOrigin();
        if ($pushProcess->isSuccessful()) {
            return CommandResponse::success(
                MessageRef::key('push.success'),
                ['branch' => $branch, 'commit' => $commitResponse->payloadData()],
                $commitResponse->getMessages(),
            );
        }

        return $this->handleFailedPush($quiet, $noPlease, $agentMode, $pleaseFallback, $commitResponse->getMessages());
    }

    /**
     * Handles a rejected normal push: error, prompt, or run please.
     *
     * @param list<ResponseMessage> $messages
     */
    protected function handleFailedPush(
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
        array $messages,
    ): CommandResponse {
        if ($agentMode) {
            if (! $pleaseFallback) {
                return $this->pushFailedResponse($messages);
            }

            return $this->normalizeResponse($this->pleaseHandler->handle(), 'Force push completed', 'Force push failed');
        }

        if ($noPlease) {
            return $this->pushFailedResponse($messages);
        }

        $interactive = ! $quiet;
        if ($interactive) {
            $confirmed = $this->prompt->confirm(
                MessageRef::key('push.confirm_please'),
                false
            );
            if (! $confirmed) {
                return $this->pushFailedResponse($messages);
            }
        }

        return $this->normalizeResponse($this->pleaseHandler->handle(), 'Force push completed', 'Force push failed');
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    protected function pushFailedResponse(array $messages): CommandResponse
    {
        return CommandResponse::error(MessageRef::key('push.error_push'), $messages);
    }

    /**
     * @return array{0: bool, 1: string|null, 2: bool, 3: bool, 4: bool, 5: bool, 6: bool}
     */
    private function normalizeHandleArguments(
        mixed $first,
        mixed $second,
        mixed $third,
        mixed $fourth,
        mixed $fifth,
        mixed $sixth,
        mixed $seventh,
        mixed $eighth,
    ): array {
        if ($first instanceof \Symfony\Component\Console\Style\SymfonyStyle) {
            return [
                (bool) $second,
                is_string($third) ? $third : null,
                (bool) $fourth,
                (bool) $fifth,
                (bool) $sixth,
                (bool) $seventh,
                (bool) $eighth,
            ];
        }

        return [
            (bool) $first,
            is_string($second) ? $second : null,
            (bool) $third,
            (bool) $fourth,
            (bool) $fifth,
            (bool) $sixth,
            (bool) $seventh,
        ];
    }

    private function normalizeResponse(CommandResponse|int $response, string $successMessage, string $errorMessage): CommandResponse
    {
        if ($response instanceof CommandResponse) {
            return $response;
        }

        return CommandResponse::fromExitCode($response, $successMessage, $errorMessage);
    }
}
