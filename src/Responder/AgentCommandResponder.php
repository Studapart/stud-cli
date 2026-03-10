<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\AgentJsonResponse;

/**
 * Generic responder for int/void handlers that lack a dedicated Responder.
 * Converts an exit code (or void success) into an AgentJsonResponse.
 */
final class AgentCommandResponder
{
    public function respondFromExitCode(int $exitCode, string $successMessage, string $errorMessage): AgentJsonResponse
    {
        return $exitCode === 0
            ? new AgentJsonResponse(true, data: ['message' => $successMessage])
            : new AgentJsonResponse(false, error: $errorMessage);
    }

    public function respondSuccess(string $message): AgentJsonResponse
    {
        return new AgentJsonResponse(true, data: ['message' => $message]);
    }
}
