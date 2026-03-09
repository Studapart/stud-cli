<?php

declare(strict_types=1);

namespace App\Response;

final class ItemCreateResponse extends AbstractResponse
{
    private function __construct(
        bool $success,
        ?string $error,
        public readonly ?string $key = null,
        public readonly ?string $self = null
    ) {
        parent::__construct($success, $error);
    }

    public static function success(string $key, string $self): self
    {
        return new self(true, null, $key, $self);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, null, null);
    }
}
