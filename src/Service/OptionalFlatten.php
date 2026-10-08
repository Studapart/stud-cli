<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Response\CommandResponse;

/**
 * Combines a host command result with an optional flatten result.
 */
final class OptionalFlatten
{
    /**
     * A commit was created when the response carries a message or fixup target.
     */
    public function commitWasCreated(CommandResponse $commit): bool
    {
        return array_key_exists('commitMessage', $commit->data) || array_key_exists('fixupSha', $commit->data);
    }

    /**
     * True when git porcelain reports any staged or unstaged change.
     */
    public function workingTreeIsDirty(GitRepository $gitRepository): bool
    {
        return trim($gitRepository->getPorcelainStatus()) !== '';
    }

    /**
     * Keep the host result, record that flatten did not run, and set rewritten to false.
     */
    public function markSkipped(CommandResponse $response): CommandResponse
    {
        $messages = $response->getMessages();
        $messages[] = ResponseMessage::notice(MessageRef::key('flatten.notice_skipped'));

        return $this->decorate($response, false, $messages);
    }

    /**
     * Merge flatten diagnostics into the host result.
     *
     * When $warnWithoutPleaseHint is true and history was rewritten, add the warning that
     * does not tell the user to run stud please.
     */
    public function mergeHostAndFlatten(
        CommandResponse $host,
        CommandResponse $flatten,
        bool $warnWithoutPleaseHint,
    ): CommandResponse {
        $messages = array_merge($host->getMessages(), $flatten->getMessages());
        if ($flatten->message !== null) {
            $messages[] = ResponseMessage::notice($flatten->message);
        }

        $rewritten = ($flatten->data['rewritten'] ?? false) === true;
        if ($rewritten && $warnWithoutPleaseHint) {
            $messages[] = ResponseMessage::warning(MessageRef::key('flatten.warning_rewritten'));
        }

        if (! $flatten->isSuccess()) {
            return CommandResponse::error(
                $flatten->getErrorMessage() ?? MessageRef::key('flatten.error_rebase', ['error' => 'unknown']),
                $messages,
                array_merge($host->data, ['rewritten' => false]),
            );
        }

        return $this->decorate($host, $rewritten, $messages);
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    private function decorate(CommandResponse $response, bool $rewritten, array $messages): CommandResponse
    {
        $data = array_merge($response->data, ['rewritten' => $rewritten]);
        if (! $response->isSuccess()) {
            return CommandResponse::error(
                $response->getErrorMessage() ?? MessageRef::key('flatten.error_rebase', ['error' => 'unknown']),
                $messages,
                $data,
            );
        }

        return CommandResponse::success($response->message, $data, $messages);
    }
}
