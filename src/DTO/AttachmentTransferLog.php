<?php

declare(strict_types=1);

namespace App\DTO;

final class AttachmentTransferLog
{
    /**
     * @var list<array{filename: string, path: string}>
     */
    private array $files = [];

    /**
     * @var list<array{filename: string|null, message: MessageRef|string}>
     */
    private array $errors = [];

    public function addFile(string $filename, string $path): void
    {
        $this->files[] = ['filename' => $filename, 'path' => $path];
    }

    public function addError(?string $filename, MessageRef|string $message): void
    {
        $this->errors[] = ['filename' => $filename, 'message' => $message];
    }

    /**
     * @return list<array{filename: string, path: string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return list<array{filename: string|null, message: MessageRef|string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
