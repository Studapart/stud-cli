<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\AgentJsonResponse;
use App\Response\CommandResponse;
use App\Service\MessageRenderer;

/**
 * Generic responder for simple command responses that lack domain-specific presentation.
 */
class AgentCommandResponder
{
    public function __construct(private readonly ?MessageRenderer $messageRenderer = null)
    {
    }

    public function respond(CommandResponse $response, bool $compact = false): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return AgentJsonResponse::fromResponse($response, renderer: $this->messageRenderer);
        }

        if ($compact) {
            return AgentJsonResponse::successWithoutData($response->diagnosticsPayload($this->messageRenderer));
        }

        return AgentJsonResponse::fromResponse(
            $response,
            $response->payloadData($this->messageRenderer),
            renderer: $this->messageRenderer,
        );
    }

    public function respondFromExitCode(
        int $exitCode,
        string $successMessage,
        string $errorMessage,
        bool $compact = false,
    ): AgentJsonResponse {
        return $this->respond(CommandResponse::fromExitCode($exitCode, $successMessage, $errorMessage), $compact);
    }

    public function respondSuccess(string $message, bool $compact = false): AgentJsonResponse
    {
        return $this->respond(CommandResponse::success($message), $compact);
    }
}
