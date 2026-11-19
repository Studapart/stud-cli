<?php

declare(strict_types=1);

namespace App\DTO;

use App\Exception\ApiException;

/**
 * A rejected Jira create and the payload keys to strip before retrying it.
 */
final class JiraCreateRetry
{
    /**
     * @param list<string> $stripKeys
     */
    public function __construct(
        public readonly array $stripKeys,
        public readonly ApiException $firstFailure,
    ) {
    }
}
