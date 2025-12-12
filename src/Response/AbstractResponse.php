<?php

declare(strict_types=1);

namespace App\Response;

abstract class AbstractResponse implements ResponseInterface
{
    protected function __construct(
        protected bool $success,
        protected ?string $error = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
