<?php

declare(strict_types=1);

namespace App\Response;

use App\Service\MessageRenderer;

final class AgentJsonResponse
{
    /**
     * @param mixed $data
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = [],
        public readonly ?string $error = null,
        public readonly bool $hasData = true,
        public readonly array $diagnostics = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        if ($this->success) {
            $payload = ['success' => true];
            if ($this->hasData) {
                $payload['data'] = $this->data;
            }

            return $this->withDiagnostics($payload);
        }

        return $this->withDiagnostics(['success' => false, 'error' => $this->error ?? 'Unknown error']);
    }

    /**
     * @param array<string, mixed> $diagnostics
     */
    public static function successWithoutData(array $diagnostics = []): self
    {
        return new self(true, hasData: false, diagnostics: $diagnostics);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponse(
        AbstractResponse $response,
        array $data = [],
        bool $compact = false,
        ?MessageRenderer $renderer = null,
    ): self {
        $diagnostics = $response->diagnosticsPayload($renderer);
        if (! $response->isSuccess()) {
            $error = $renderer?->render($response->getErrorMessage()) ?? $response->getError() ?? 'Unknown error';

            return new self(false, error: $error, diagnostics: $diagnostics);
        }

        if ($compact && $data === []) {
            return self::successWithoutData($diagnostics);
        }

        return new self(true, data: $data, diagnostics: $diagnostics);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withDiagnostics(array $payload): array
    {
        if ($this->diagnostics !== []) {
            $payload['diagnostics'] = $this->diagnostics;
        }

        return $payload;
    }
}
