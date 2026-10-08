<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\GitRepositoryAware;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\OptionalFlatten;
use App\Service\Prompt\PromptInterface;

class PushHandler implements GitRepositoryAware
{
    private readonly OptionalFlatten $optionalFlatten;

    public function __construct(
        private readonly CommitHandler $commitHandler,
        private readonly GitRepository $gitRepository,
        private readonly PleaseHandler $pleaseHandler,
        mixed $_translator,
        private readonly PromptInterface $prompt,
        private readonly FlattenHandler $flattenHandler,
    ) {
        unset($_translator);
        $this->optionalFlatten = new OptionalFlatten();
    }

    /**
     * Run commit (same semantics as stud commit), then push HEAD to origin. On a failed non-fast-forward
     * push, optionally delegates to {@see PleaseHandler} per quiet, agent, `--no-please` (CLI only), and
     * agent `pleaseFallback` (JSON / folded from CLI `--no-please` when using `--agent`).
     *
     * When nothing is staged and `--all` / `stageAll` was not requested, skips the commit phase so a dirty
     * working tree does not block pushing existing commits (standalone `stud commit` still errors).
     *
     * When $flatten is true, squash fixups only after a created commit leaves a clean tree, then publish
     * rewritten history with the safe force-push.
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

        if (! $flatten) {
            return $this->publishCurrent($commitResponse, $quiet, $noPlease, $agentMode, $pleaseFallback, null);
        }

        return $this->publishWithFlatten($commitResponse, $quiet, $noPlease, $agentMode, $pleaseFallback);
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
     * Flatten only after a created commit leaves a clean tree, then publish once.
     */
    protected function publishWithFlatten(
        CommandResponse $commitResponse,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
    ): CommandResponse {
        $prepared = $this->flattenCommit($commitResponse);
        if (! $prepared->isSuccess()) {
            return $this->nestFailedFlatten($commitResponse, $prepared);
        }

        $rewritten = ($prepared->data['rewritten'] ?? false) === true;
        if (! $rewritten) {
            return $this->publishCurrent($prepared, $quiet, $noPlease, $agentMode, $pleaseFallback, false);
        }

        if (! $this->confirmFlattenPublish($quiet, $agentMode)) {
            return $this->flattenPushDeclined($prepared);
        }

        return $this->finishFlattenedPublish($prepared, $this->runPlease($quiet || $agentMode), true);
    }

    /**
     * Skip flatten unless this command created a commit and the tree is clean.
     */
    protected function flattenCommit(CommandResponse $commitResponse): CommandResponse
    {
        $created = $this->optionalFlatten->commitWasCreated($commitResponse);
        $dirty = $this->optionalFlatten->workingTreeIsDirty($this->gitRepository);
        if (! $created || $dirty) {
            return $this->optionalFlatten->markSkipped($commitResponse);
        }

        return $this->optionalFlatten->mergeHostAndFlatten(
            $commitResponse,
            $this->flattenHandler->handle(false),
            true,
        );
    }

    /**
     * Publish with the existing normal push. Rewritten history is not handled here.
     */
    protected function publishCurrent(
        CommandResponse $commitResponse,
        bool $quiet,
        bool $noPlease,
        bool $agentMode,
        bool $pleaseFallback,
        ?bool $rewritten,
    ): CommandResponse {
        $branch = $this->gitRepository->getCurrentBranchName();
        $pushProcess = $this->gitRepository->pushHeadToOrigin();
        if ($pushProcess->isSuccessful()) {
            return $this->pushSuccess($branch, $commitResponse, $rewritten);
        }

        $failed = $this->handleFailedPush($quiet, $noPlease, $agentMode, $pleaseFallback, $commitResponse->getMessages());
        if ($rewritten === null) {
            return $failed;
        }

        return $this->finishFlattenedPublish($commitResponse, $failed, $rewritten);
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

    /**
     * Interactive push is the only confirmation before publishing a rewrite.
     */
    protected function confirmFlattenPublish(bool $quiet, bool $agentMode): bool
    {
        if ($quiet || $agentMode) {
            return true;
        }

        return $this->prompt->confirm(MessageRef::key('push.confirm_flatten_publish'), false);
    }

    /**
     * Decline leaves the rewritten commits local and does not push.
     */
    protected function flattenPushDeclined(CommandResponse $commitResponse): CommandResponse
    {
        return CommandResponse::error(
            MessageRef::key('push.error_push'),
            $commitResponse->getMessages(),
            [
                'commit' => $commitResponse->payloadData(),
                'rewritten' => true,
                'published' => false,
            ],
        );
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

    protected function pushSuccess(string $branch, CommandResponse $commitResponse, ?bool $rewritten): CommandResponse
    {
        $data = [
            'branch' => $branch,
            'commit' => $commitResponse->payloadData(),
        ];
        if ($rewritten !== null) {
            $data['rewritten'] = $rewritten;
            $data['published'] = true;
        }

        return CommandResponse::success(
            MessageRef::key('push.success'),
            $data,
            $commitResponse->getMessages(),
        );
    }

    /**
     * Keep the commit payload visible and do not publish after a flatten failure.
     */
    protected function nestFailedFlatten(CommandResponse $commitResponse, CommandResponse $failed): CommandResponse
    {
        return CommandResponse::error(
            $failed->getErrorMessage() ?? MessageRef::key('flatten.error_rebase', ['error' => 'unknown']),
            $failed->getMessages(),
            array_merge($failed->data, [
                'commit' => $commitResponse->payloadData(),
                'rewritten' => false,
            ]),
        );
    }

    protected function finishFlattenedPublish(
        CommandResponse $commitResponse,
        CommandResponse $published,
        bool $rewritten,
    ): CommandResponse {
        $data = [
            'branch' => $this->gitRepository->getCurrentBranchName(),
            'commit' => $commitResponse->payloadData(),
            'rewritten' => $rewritten,
            'published' => $published->isSuccess(),
        ];
        $messages = $this->combineMessages($commitResponse, $published);
        if ($published->isSuccess()) {
            return CommandResponse::success(
                $published->message ?? MessageRef::key('push.success'),
                $data,
                $messages,
            );
        }

        return CommandResponse::error(
            $published->getErrorMessage() ?? MessageRef::key('push.error_push'),
            $messages,
            $data,
        );
    }

    /**
     * @return list<ResponseMessage>
     */
    private function combineMessages(CommandResponse $commitResponse, CommandResponse $published): array
    {
        $commitMessages = $commitResponse->getMessages();
        $publishedMessages = $published->getMessages();
        if ($publishedMessages === [] || $publishedMessages === $commitMessages) {
            return $commitMessages;
        }

        return array_merge($commitMessages, $publishedMessages);
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

    private function normalizeResponse(CommandResponse|int $response, string $successMessage, string $errorMessage): CommandResponse
    {
        if ($response instanceof CommandResponse) {
            return $response;
        }

        return CommandResponse::fromExitCode($response, $successMessage, $errorMessage);
    }
}
