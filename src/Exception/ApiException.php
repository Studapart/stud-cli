<?php

declare(strict_types=1);

namespace App\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(
        string $userMessage,
        private readonly string $technicalDetails,
        private readonly ?int $statusCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function getTechnicalDetails(): string
    {
        return $this->technicalDetails;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
