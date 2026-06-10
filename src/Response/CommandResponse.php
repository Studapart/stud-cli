<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Service\MessageRenderer;

final class CommandResponse extends AbstractResponse
{
    /**
     * @param list<ResponseMessage> $messages
     * @param array<string, mixed> $data
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly MessageRef|string|null $message = null,
        public readonly array $data = [],
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param list<ResponseMessage> $messages
     * @param array<string, mixed> $data
     */
    public static function success(MessageRef|string|null $message = null, array $data = [], array $messages = []): self
    {
        return new self(true, null, $message, $data, $messages);
    }

    /**
     * @param list<ResponseMessage> $messages
     * @param array<string, mixed> $data
     */
    public static function error(MessageRef|string $error, array $messages = [], array $data = []): self
    {
        return new self(false, $error, null, $data, $messages);
    }

    /**
     * @param list<ResponseMessage> $messages
     * @param array<string, mixed> $data
     */
    public static function fromExitCode(
        int $exitCode,
        MessageRef|string $successMessage,
        MessageRef|string $errorMessage,
        array $messages = [],
        array $data = [],
    ): self {
        if ($exitCode !== 0) {
            return self::error($errorMessage, $messages, $data);
        }

        return self::success($successMessage, $data, $messages);
    }

    public function hasReusableData(): bool
    {
        return $this->message !== null || $this->data !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadData(?MessageRenderer $renderer = null): array
    {
        $data = $this->data;
        if ($this->message !== null) {
            $data = ['message' => $renderer?->render($this->message) ?? (string) $this->message] + $data;
        }

        return $data;
    }
}
