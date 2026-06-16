<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\Enum\WorkflowChannel;

/**
 * Records recoverable domain failures at debug verbosity on a workflow recorder.
 *
 * Per ADR-017, diagnostics belong on response DTOs / workflow recorders — not Logger.
 */
final class RecoverableExceptionLogger
{
    public static function logToRecorder(
        WorkflowEntryRecorder $recorder,
        \Throwable $exception,
        string $context,
        WorkflowChannel $channel = WorkflowChannel::Default,
    ): void {
        $recorder->addLine(
            WorkflowEntryRecorder::VERBOSITY_DEBUG,
            "    <fg=gray>{$context}: {$exception->getMessage()}</>",
            $channel,
        );
    }
}
