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
            $error = AgentJsonResponse::fromResponse($response, renderer: $this->messageRenderer);
            if ($response->data === []) {
                return $error;
            }

            return new AgentJsonResponse(
                false,
                data: $response->data,
                error: $error->error,
                diagnostics: $response->diagnosticsPayload($this->messageRenderer),
            );
        }

        if ($compact) {
            $fields = $this->flattenFields($response);
            $diagnostics = $response->diagnosticsPayload($this->messageRenderer);
            if ($fields === []) {
                return AgentJsonResponse::successWithoutData($diagnostics);
            }

            return new AgentJsonResponse(true, data: $fields, diagnostics: $diagnostics);
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

    /**
     * Compact output keeps completion-only successes data-free and surfaces flatten fields when requested.
     *
     * @return array<string, mixed>
     */
    private function flattenFields(CommandResponse $response): array
    {
        $fields = [];
        foreach (['rewritten', 'published'] as $key) {
            if (array_key_exists($key, $response->data)) {
                $fields[$key] = $response->data[$key];
            }
        }

        return $fields;
    }
}
