<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;

final class ItemUploadResponse extends AbstractResponse
{
    /**
     * @param list<array{filename: string, path: string}>                         $files
     * @param list<array{filename: string|null, message: MessageRef|string}> $errors
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $files,
        public readonly array $errors
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param list<array{filename: string, path: string}>                         $files
     * @param list<array{filename: string|null, message: MessageRef|string}> $errors
     */
    public static function result(array $files, array $errors): self
    {
        return new self(true, null, $files, $errors);
    }

    public static function fatal(MessageRef|string $error): self
    {
        return new self(false, $error, [], []);
    }
}
