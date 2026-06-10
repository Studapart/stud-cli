<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ResponseMessageLevel;
use App\Service\MessageRenderer;

final class ResponseMessage
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly ResponseMessageLevel $level,
        public readonly MessageRef|string $message,
        public readonly ?string $technicalDetails = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(MessageRef|string $message, ?string $technicalDetails = null, array $context = []): self
    {
        return new self(ResponseMessageLevel::Error, $message, $technicalDetails, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(MessageRef|string $message, ?string $technicalDetails = null, array $context = []): self
    {
        return new self(ResponseMessageLevel::Warning, $message, $technicalDetails, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function notice(MessageRef|string $message, ?string $technicalDetails = null, array $context = []): self
    {
        return new self(ResponseMessageLevel::Notice, $message, $technicalDetails, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(MessageRef|string $message, ?string $technicalDetails = null, array $context = []): self
    {
        return new self(ResponseMessageLevel::Info, $message, $technicalDetails, $context);
    }

    /**
     * @return array{message: string, technicalDetails?: string, context?: array<string, mixed>}
     */
    public function toPayload(?MessageRenderer $renderer = null): array
    {
        $payload = ['message' => $this->renderMessage($renderer)];
        if ($this->technicalDetails !== null) {
            $payload['technicalDetails'] = $this->technicalDetails;
        }
        if ($this->context !== []) {
            $payload['context'] = $this->context;
        }

        return $payload;
    }

    private function renderMessage(?MessageRenderer $renderer): string
    {
        if ($renderer !== null) {
            return $renderer->render($this->message) ?? '';
        }

        return (string) $this->message;
    }
}
