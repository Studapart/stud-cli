<?php

declare(strict_types=1);

namespace App\Response;

final class ItemDownloadResponse extends AbstractResponse
{
    /**
     * @param list<array{filename: string, path: string}>             $files
     * @param list<array{filename: string|null, message: string}> $errors
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $files,
        public readonly array $errors
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param list<array{filename: string, path: string}>             $files
     * @param list<array{filename: string|null, message: string}> $errors
     */
    public static function result(array $files, array $errors): self
    {
        return new self(true, null, $files, $errors);
    }

    public static function fatal(string $error): self
    {
        return new self(false, $error, [], []);
    }
}
