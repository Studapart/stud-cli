<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Result of {@see ConfigReadinessEvaluator::evaluate()}.
 *
 * @param list<string> $notices
 */
final class ConfigReadinessEvaluation
{
    /**
     * @param list<string> $notices
     */
    public function __construct(
        public readonly string $globalRollup,
        public readonly string $projectRollup,
        public readonly bool $success,
        public readonly array $notices,
    ) {
    }
}
