<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\AgentJsonResponse;

/**
 * Generic responder for int/void handlers that lack a dedicated Responder.
 * Converts an exit code (or void success) into an AgentJsonResponse.
 */
class AgentCommandResponder
{
    public function respondFromExitCode(
        int $exitCode,
        string $successMessage,
        string $errorMessage,
        bool $compact = false,
    ): AgentJsonResponse {
        if ($exitCode !== 0) {
            return new AgentJsonResponse(false, error: $errorMessage);
        }

        return $this->respondSuccess($successMessage, $compact);
    }

    public function respondSuccess(string $message, bool $compact = false): AgentJsonResponse
    {
        if ($compact) {
            return AgentJsonResponse::successWithoutData();
        }

        return new AgentJsonResponse(true, data: ['message' => $message]);
    }
}
