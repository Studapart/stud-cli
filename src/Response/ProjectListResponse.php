<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\Project;

final class ProjectListResponse extends AbstractResponse
{
    /**
     * @param Project[] $projects
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $projects
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param Project[] $projects
     */
    public static function success(array $projects): self
    {
        return new self(true, null, $projects);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, []);
    }
}
