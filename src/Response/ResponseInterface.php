<?php

declare(strict_types=1);

namespace App\Response;

interface ResponseInterface
{
    public function isSuccess(): bool;

    public function getError(): ?string;
}
