<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

/**
 * Result of {@see ConfigReadinessEvaluator::evaluate()}.
 *
 * @param list<MessageRef|string> $notices
 */
final class ConfigReadinessEvaluation
{
    /**
     * @param list<MessageRef|string> $notices
     */
    public function __construct(
        public readonly string $globalRollup,
        public readonly string $projectRollup,
        public readonly bool $success,
        public readonly array $notices,
    ) {
    }
}
