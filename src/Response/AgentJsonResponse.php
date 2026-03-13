<?php

declare(strict_types=1);

namespace App\Response;

final class AgentJsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function toPayload(): array
    {
        if ($this->success) {
            return ['success' => true, 'data' => $this->data];
        }

        return ['success' => false, 'error' => $this->error ?? 'Unknown error'];
    }
}
