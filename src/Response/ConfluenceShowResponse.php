<?php

declare(strict_types=1);

namespace App\Response;

final class ConfluenceShowResponse extends AbstractResponse
{
    private function __construct(
        bool $success,
        ?string $error,
        public readonly ?string $id = null,
        public readonly ?string $title = null,
        public readonly ?string $url = null,
        public readonly ?string $body = null,
    ) {
        parent::__construct($success, $error);
    }

    public static function success(string $id, string $title, string $url, string $body): self
    {
        return new self(true, null, $id, $title, $url, $body);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, null, null, null, null);
    }
}
