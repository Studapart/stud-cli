<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\Project;
use App\DTO\ResponseMessage;

final class ProjectListResponse extends AbstractResponse
{
    /**
     * @param Project[]             $projects
     * @param list<string>          $projectProviders
     * @param list<ResponseMessage> $messages
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $projects,
        public readonly array $projectProviders = [],
        public readonly bool $multiProvider = false,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param Project[]             $projects
     * @param list<string>          $projectProviders
     * @param list<ResponseMessage> $messages
     */
    public static function success(
        array $projects,
        array $projectProviders = [],
        bool $multiProvider = false,
        array $messages = [],
    ): self {
        return new self(true, null, $projects, $projectProviders, $multiProvider, $messages);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, []);
    }
}
