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
        mixed $eighth = false,
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
        );
        $commitResponse = $this->runCommitPhase($isNew, $message, $stageAll, $quiet);
        if (! $commitResponse->isSuccess()) {
            return $commitResponse;
        }

        $prepared = $this->prepareFlatten($commitResponse, $flatten);
        if (! $prepared->isSuccess()) {
            return $prepared;
        }

        return $this->publish($prepared, $quiet, $noPlease, $agentMode, $pleaseFallback);
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
            (bool) $eighth,
        ];
    }

    private function prepareFlatten(CommandResponse $commitResponse, bool $flatten): CommandResponse
    {
        if (! $flatten) {
            return $commitResponse;
        }

        return $this->withPublishWarning($this->flattenHandler->applyWhenSafe($commitResponse, true));
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

    private function publish(
        CommandResponse $prepared,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): CommandResponse {
        if (($prepared->data['rewritten'] ?? false) === true) {
            return $this->publishRewritten($prepared, $quiet, $agentMode);
        }
        $response = $this->pushCurrent($prepared, $quiet, $noPlease, $agentMode, $pleaseFallback);
        if (! array_key_exists('rewritten', $prepared->data)) {
            return $response;
        }
        $response = $this->retainFlattenContext($response, $prepared);
        if (! $response->isSuccess()) {
            return $response;
        }

        return $this->markPublished($response, false);
    }

    private function pushCurrent(
        CommandResponse $prepared,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): CommandResponse {
        $pushProcess = $this->gitRepository->pushHeadToOrigin();
        if ($pushProcess->isSuccessful()) {
            return CommandResponse::success(
                MessageRef::key('push.success'),
                [
                    'branch' => $this->gitRepository->getCurrentBranchName(),
                    'commit' => $this->commitPayload($prepared),
                ],
                $prepared->getMessages(),
            );
        }

        return $this->handleFailedPush($quiet, $noPlease, $agentMode, $pleaseFallback, $prepared->getMessages());
    }

    private function publishRewritten(CommandResponse $prepared, bool $quiet, bool $agentMode): CommandResponse
    {
        if (! $this->confirmFlattenPublish($quiet, $agentMode)) {
            return $this->unpublishedRewrite($prepared, MessageRef::key('push.error_push'), $prepared->getMessages());
        }
        $please = $this->runPlease($quiet || $agentMode);
        $messages = array_merge($prepared->getMessages(), $please->getMessages());
        if (! $please->isSuccess()) {
            return $this->unpublishedRewrite(
                $prepared,
                $please->getErrorMessage() ?? MessageRef::key('push.error_push'),
                $messages,
            );
        }

        return CommandResponse::success(
            $please->message ?? MessageRef::key('push.success'),
            [
                'branch' => $this->gitRepository->getCurrentBranchName(),
                'commit' => $this->commitPayload($prepared),
                'published' => true,
                'rewritten' => true,
            ],
            $messages,
        );
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    private function unpublishedRewrite(CommandResponse $prepared, MessageRef|string $error, array $messages): CommandResponse
    {
        return CommandResponse::error($error, $messages, [
            'commit' => $this->commitPayload($prepared),
            'rewritten' => true,
        ]);
    }

    private function confirmFlattenPublish(bool $quiet, bool $agentMode): bool
    {
        if ($quiet || $agentMode) {
            return true;
        }

        return $this->prompt->confirm(MessageRef::key('push.confirm_flatten_publish'), false);
    }

    /**
     * Keep the flatten flag and notices when a later push or please fallback replaces the payload.
     */
    private function retainFlattenContext(CommandResponse $response, CommandResponse $prepared): CommandResponse
    {
        $data = $response->data;
        $data['rewritten'] = $prepared->data['rewritten'] ?? false;
        if (! array_key_exists('commit', $data)) {
            $data['commit'] = $this->commitPayload($prepared);
        }
        $messages = $this->mergeFlattenMessages($prepared->getMessages(), $response->getMessages());
        if ($response->isSuccess()) {
            return CommandResponse::success($response->message, $data, $messages);
        }

        return CommandResponse::error(
            $response->getErrorMessage() ?? MessageRef::key('push.error_push'),
            $messages,
            $data,
        );
    }

    /**
     * @param list<ResponseMessage> $flattenMessages
     * @param list<ResponseMessage> $pushMessages
     * @return list<ResponseMessage>
     */
    private function mergeFlattenMessages(array $flattenMessages, array $pushMessages): array
    {
        $merged = $flattenMessages;
        $seen = [];
        foreach ($flattenMessages as $message) {
            $seen[$this->messageIdentity($message)] = true;
        }
        foreach ($pushMessages as $message) {
            $identity = $this->messageIdentity($message);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $merged[] = $message;
        }

        return $merged;
    }

    private function messageIdentity(ResponseMessage $message): string
    {
        return $message->level->value . '|' . (string) $message->message . '|' . ($message->technicalDetails ?? '');
    }

    private function markPublished(CommandResponse $response, bool $rewritten): CommandResponse
    {
        $data = $response->data;
        $data['published'] = true;
        $data['rewritten'] = $rewritten;

        return CommandResponse::success($response->message, $data, $response->getMessages());
    }

    /**
     * @return array<string, mixed>
     */
    private function commitPayload(CommandResponse $commit): array
    {
        $payload = $commit->payloadData();
        unset($payload['rewritten'], $payload['published']);

        return $payload;
    }

    private function normalizeResponse(CommandResponse|int $response, string $successMessage, string $errorMessage): CommandResponse
    {
        if ($response instanceof CommandResponse) {
            return $response;
        }

        return CommandResponse::fromExitCode($response, $successMessage, $errorMessage);
    }
}
