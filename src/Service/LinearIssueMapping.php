<?php

declare(strict_types=1);

namespace App\Service;

final class LinearIssueMapping
{
    public function __construct(
        public readonly LinearIssueFieldTranslator $translator = new LinearIssueFieldTranslator(),
        public readonly LinearIssueMapper $mapper = new LinearIssueMapper(),
    ) {
    }
}
