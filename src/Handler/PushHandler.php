<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\GitRepositoryAware;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

class PushHandler implements GitRepositoryAware
{
    public function __construct(
        private readonly CommitHandler $commitHandler,
        private readonly GitRepository $gitRepository,
        private readonly PleaseHandler $pleaseHandler,
        mixed $_translator,
        private readonly PromptInterface $prompt,
        private readonly FlattenHandler $flattenHandler,
    ) {
        unset($_translator);
    }

    /**
     * Run commit (same semantics as stud commit), then push HEAD to origin. On a failed non-fast-forward
     * push, optionally delegates to {@see PleaseHandler} per quiet, agent, `--no-please` (CLI only), and
     * agent `pleaseFallback` (JSON / folded from CLI `--no-please` when using `--agent`).
     *
     * When nothing is staged and `--all` / `stageAll` was not requested, skips the commit phase so a dirty
     * working tree does not block pushing existing commits (standalone `stud commit` still errors).
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
        mixed $ninth = false,
    ): CommandResponse {
        [$isNew, $message, $stageAll, $quiet, $noPlease, $agentMode, $pleaseFallback, $flatten] = $this->normalizeHandleArguments(
            $first,
            $second,
            $third,
            $fourth,
            $fifth,
            $sixth,
            $seventh,
            $eighth,
            $ninth,
            func_num_args(),
        );
        $commitResponse = $this->runCommitPhase($isNew, $message, $stageAll, $quiet);
        if (! $commitResponse->isSuccess()) {
            return $flatten ? $commitResponse->withFlattenResult(false, []) : $commitResponse;
        }
        if ($flatten) {
            return $this->pushAfterOptionalFlatten($commitResponse, $quiet, $noPlease, $agentMode, $pleaseFallback);
        }

        return $this->pushExistingHistory($commitResponse, $quiet, $noPlease, $agentMode, $pleaseFallback);
    }

    /**
     * Publish after an optional flatten. A flatten failure does not push.
     */
    protected function pushAfterOptionalFlatten(
        CommandResponse $commitResponse,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): CommandResponse {
        $flattened = $this->flattenHandler->applyToCommitResult($commitResponse);
        if (! $flattened->isSuccess()) {
            return $flattened;
        }
        if (($flattened->data['rewritten'] ?? false) === true) {
            return $this->publishRewrittenHistory($flattened, $quiet, $agentMode);
        }

        return $this->withFlattenPublication(
            $this->pushExistingHistory($flattened, $quiet, $noPlease, $agentMode, $pleaseFallback),
            $flattened,
        );
    }

    /**
     * Ask before publishing rewritten history, except in quiet and agent mode.
     */
    protected function publishRewrittenHistory(CommandResponse $commit, bool $quiet, bool $agentMode): CommandResponse
    {
        if (! $this->confirmsFlattenPublish($quiet, $agentMode)) {
            return CommandResponse::error(
                MessageRef::key('push.error_push'),
                $commit->getMessages(),
                $this->flattenPublicationData($commit, false),
            );
        }

        return $this->withFlattenPublication($this->runPlease($quiet || $agentMode), $commit);
    }

    protected function confirmsFlattenPublish(bool $quiet, bool $agentMode): bool
    {
        if ($quiet || $agentMode) {
            return true;
        }

        return $this->prompt->confirm(MessageRef::key('push.confirm_flatten_publish'), false);
    }

    protected function withFlattenPublication(CommandResponse $published, CommandResponse $commit): CommandResponse
    {
        $data = $this->flattenPublicationData($commit, $published->isSuccess());
        $messages = $this->prependMissingMessages($published->getMessages(), $commit->getMessages());
        if ($published->isSuccess()) {
            return CommandResponse::success($published->message, $data, $messages);
        }

        return CommandResponse::error(
            $published->getErrorMessage() ?? MessageRef::key('push.error_push'),
            $messages,
            $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function flattenPublicationData(CommandResponse $commit, bool $published): array
    {
        $commitData = $commit->payloadData();
        unset($commitData['rewritten']);

        return [
            'branch' => $this->gitRepository->getCurrentBranchName(),
            'commit' => $commitData,
            'rewritten' => (bool) ($commit->data['rewritten'] ?? false),
            'published' => $published,
        ];
    }

    /**
     * @param list<ResponseMessage> $messages
     * @param list<ResponseMessage> $prefix
     * @return list<ResponseMessage>
     */
    protected function prependMissingMessages(array $messages, array $prefix): array
    {
        if ($prefix === [] || array_slice($messages, 0, count($prefix)) == $prefix) {
            return $messages;
        }

        return array_merge($prefix, $messages);
    }

    protected function pushExistingHistory(
        CommandResponse $commitResponse,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): CommandResponse {
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
     * Commit when stageAll or staged files exist; otherwise soft-skip (dirty-tree notice if needed).
     */
    protected function runCommitPhase(bool $isNew, ?string $message, bool $stageAll, bool $quiet): CommandResponse
    {
        if (! $stageAll && ! $this->gitRepository->hasStagedChanges()) {
            return $this->skippedCommitResponse();
        }

        return $this->normalizeResponse(
            $this->commitHandler->handle($isNew, $message, $stageAll, $quiet),
            'Commit created',
            'Commit failed',
        );
    }

    /**
     * @return CommandResponse Success with optional dirty-tree notice
     */
    protected function skippedCommitResponse(): CommandResponse
    {
        $messages = [];
        if (trim($this->gitRepository->getPorcelainStatus()) !== '') {
            $messages[] = ResponseMessage::notice(MessageRef::key('push.note_uncommitted_left'));
        }

        return CommandResponse::success(messages: $messages);
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
        $pleaseQuiet = $quiet || $agentMode;

        if ($agentMode) {
            if (! $pleaseFallback) {
                return $this->pushFailedResponse($messages);
            }

            return $this->runPlease($pleaseQuiet);
        }

        if ($noPlease) {
            return $this->pushFailedResponse($messages);
        }

        if (! $quiet) {
            $confirmed = $this->prompt->confirm(MessageRef::key('push.confirm_please'), false);
            if (! $confirmed) {
                return $this->pushFailedResponse($messages);
            }
        }

        return $this->runPlease($pleaseQuiet);
    }

    protected function runPlease(bool $quiet): CommandResponse
    {
        return $this->normalizeResponse(
            $this->pleaseHandler->handle($quiet),
            'Force push completed',
            'Force push failed',
        );
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    protected function pushFailedResponse(array $messages): CommandResponse
    {
        return CommandResponse::error(MessageRef::key('push.error_push'), $messages);
    }

    /**
     * @return array{0: bool, 1: string|null, 2: bool, 3: bool, 4: bool, 5: bool, 6: bool, 7: bool}
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
        mixed $ninth,
        int $argumentCount,
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
                (bool) $ninth,
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
            $argumentCount >= 8 && (bool) $eighth,
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
