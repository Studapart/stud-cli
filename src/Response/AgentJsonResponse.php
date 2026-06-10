<?php

declare(strict_types=1);

namespace App\Response;

final class AgentJsonResponse
{
    /**
     * @param mixed $data
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = [],
        public readonly ?string $error = null,
        public readonly bool $hasData = true,
    ) {
    }

    /**
     * @return array{success: bool, data?: mixed, error?: string}
     */
    public function toPayload(): array
    {
        if ($this->success) {
            if (! $this->hasData) {
                return ['success' => true];
            }

            return ['success' => true, 'data' => $this->data];
        }

        return ['success' => false, 'error' => $this->error ?? 'Unknown error'];
    }

    public static function successWithoutData(): self
    {
        return new self(true, hasData: false);
    }
}
