<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\Filter;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;

final class FilterListResponse extends AbstractResponse
{
    /**
     * @param Filter[]              $filters
     * @param list<string>          $filterProviders
     * @param list<ResponseMessage> $messages
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $filters,
        public readonly array $filterProviders = [],
        public readonly bool $multiProvider = false,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param Filter[]              $filters
     * @param list<string>          $filterProviders
     * @param list<ResponseMessage> $messages
     */
    public static function success(
        array $filters,
        array $filterProviders = [],
        bool $multiProvider = false,
        array $messages = [],
    ): self {
        return new self(true, null, $filters, $filterProviders, $multiProvider, $messages);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, []);
    }
}
