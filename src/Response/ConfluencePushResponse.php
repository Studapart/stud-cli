<?php

declare(strict_types=1);

namespace App\Response;

final class ConfluencePushResponse extends AbstractResponse
{
    private function __construct(
        bool $success,
        ?string $error,
        public readonly ?string $pageId = null,
        public readonly ?string $title = null,
        public readonly ?string $url = null,
        public readonly ?string $action = null,
    ) {
        parent::__construct($success, $error);
    }

    public static function success(string $pageId, string $title, string $url, string $action): self
    {
        return new self(true, null, $pageId, $title, $url, $action);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, null, null, null, null);
    }
}
