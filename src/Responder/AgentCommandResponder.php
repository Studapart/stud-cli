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
            return AgentJsonResponse::fromResponse($response, $response->data, renderer: $this->messageRenderer);
        }

        if ($compact) {
            $reusable = $this->reusableCompactData($response);
            $diagnostics = $response->diagnosticsPayload($this->messageRenderer);
            if ($reusable === []) {
                return AgentJsonResponse::successWithoutData($diagnostics);
            }

            return new AgentJsonResponse(true, data: $reusable, diagnostics: $diagnostics);
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
     * Compact output stays data-free unless flatten recorded reusable fields.
     *
     * @return array<string, mixed>
     */
    private function reusableCompactData(CommandResponse $response): array
    {
        $reusable = [];
        foreach (['rewritten', 'published'] as $key) {
            if (array_key_exists($key, $response->data)) {
                $reusable[$key] = $response->data[$key];
            }
        }

        return $reusable;
    }
}
