<?php

declare(strict_types=1);

namespace App\Exception;

use App\DTO\MessageRef;

final class ApiExceptionWithHint extends ApiException
{
    public function __construct(ApiException $source, private readonly MessageRef $hint)
    {
        parent::__construct($source->getMessage(), $source->getTechnicalDetails(), $source->getStatusCode(), $source);
    }

    public function getResolutionHint(): MessageRef
    {
        return $this->hint;
    }
}
