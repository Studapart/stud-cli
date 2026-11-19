<?php

declare(strict_types=1);

namespace App\Service;

final class SubmitAssembly
{
    public function __construct(
        public readonly SubmitPrBodyBuilder $body,
        public readonly SubmitLabelPlanner $labels,
    ) {
    }
}
