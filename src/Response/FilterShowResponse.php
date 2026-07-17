<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkItem;

final class FilterShowResponse extends AbstractResponse
{
    /**
     * @param WorkItem[]            $issues
     * @param list<string>          $issueProviders
     * @param list<ResponseMessage> $messages
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $issues,
        public readonly string $filterName,
        public readonly array $issueProviders = [],
        public readonly bool $multiProvider = false,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param WorkItem[]            $issues
     * @param list<string>          $issueProviders
     * @param list<ResponseMessage> $messages
     */
    public static function success(
        array $issues,
        string $filterName,
        array $issueProviders = [],
        bool $multiProvider = false,
        array $messages = [],
    ): self {
        return new self(true, null, $issues, $filterName, $issueProviders, $multiProvider, $messages);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, [], '');
    }
}
