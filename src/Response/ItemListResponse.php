<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkItem;

final class ItemListResponse extends AbstractResponse
{
    /**
     * @param list<WorkItem>        $issues
     * @param list<string>          $issueProviders
     * @param list<ResponseMessage> $messages
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $issues,
        public readonly bool $all,
        public readonly ?string $project,
        public readonly array $issueProviders = [],
        public readonly bool $multiProvider = false,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param list<WorkItem>        $issues
     * @param list<string>          $issueProviders
     * @param list<ResponseMessage> $messages
     */
    public static function success(
        array $issues,
        bool $all,
        ?string $project,
        array $issueProviders = [],
        bool $multiProvider = false,
        array $messages = [],
    ): self {
        return new self(true, null, $issues, $all, $project, $issueProviders, $multiProvider, $messages);
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    public static function error(MessageRef|string $error, array $messages = []): self
    {
        return new self(false, $error, [], false, null, [], false, $messages);
    }
}
