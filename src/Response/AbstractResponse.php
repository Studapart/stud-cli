<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\ResponseMessageLevel;
use App\Service\MessageRenderer;

abstract class AbstractResponse implements ResponseInterface
{
    /**
     * @param list<ResponseMessage> $messages
     */
    protected function __construct(
        protected bool $success,
        protected MessageRef|string|null $error = null,
        protected array $messages = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getError(): ?string
    {
        return $this->error === null ? null : (string) $this->error;
    }

    public function getErrorMessage(): MessageRef|string|null
    {
        return $this->error;
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getErrors(): array
    {
        return $this->messagesForLevel(ResponseMessageLevel::Error);
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getWarnings(): array
    {
        return $this->messagesForLevel(ResponseMessageLevel::Warning);
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getNotices(): array
    {
        return $this->messagesForLevel(ResponseMessageLevel::Notice);
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getInfos(): array
    {
        return $this->messagesForLevel(ResponseMessageLevel::Info);
    }

    /**
     * @return list<string>
     */
    public function getTechnicalDetails(): array
    {
        return array_values(array_filter(
            array_map(
                fn (ResponseMessage $message): ?string => $message->technicalDetails,
                $this->messages,
            ),
            fn (?string $details): bool => $details !== null,
        ));
    }

    public function hasDiagnostics(): bool
    {
        return $this->messages !== [];
    }

    /**
     * @return array{errors?: list<array<string, mixed>>, warnings?: list<array<string, mixed>>, notices?: list<array<string, mixed>>, info?: list<array<string, mixed>>}
     */
    public function diagnosticsPayload(?MessageRenderer $renderer = null): array
    {
        $payload = [];
        $this->addDiagnostics($payload, 'errors', $this->getErrors(), $renderer);
        $this->addDiagnostics($payload, 'warnings', $this->getWarnings(), $renderer);
        $this->addDiagnostics($payload, 'notices', $this->getNotices(), $renderer);
        $this->addDiagnostics($payload, 'info', $this->getInfos(), $renderer);

        return $payload;
    }

    /**
     * @return list<ResponseMessage>
     */
    private function messagesForLevel(ResponseMessageLevel $level): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (ResponseMessage $message): bool => $message->level === $level,
        ));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $payload
     * @param list<ResponseMessage> $messages
     */
    private function addDiagnostics(array &$payload, string $key, array $messages, ?MessageRenderer $renderer): void
    {
        if ($messages === []) {
            return;
        }

        $payload[$key] = array_map(
            fn (ResponseMessage $message): array => $message->toPayload($renderer),
            $messages,
        );
    }
}
