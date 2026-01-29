<?php

declare(strict_types=1);

namespace App\Exception;

class GitException extends \RuntimeException
{
    public function __construct(
        string $userMessage,
        private readonly string $technicalDetails,
        ?\Throwable $previous = null
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function getTechnicalDetails(): string
    {
        return $this->technicalDetails;
    }
}
