<?php

declare(strict_types=1);

namespace App\DTO;

use App\Response\ConfigValidateResponse;

/**
 * Jira, Git, and Linear ping results for the legacy config:validate response factory.
 */
final class ConfigValidatePingResults
{
    public function __construct(
        public readonly ConfigValidateIntegrationPing $jira,
        public readonly ConfigValidateIntegrationPing $git,
        public readonly ConfigValidateIntegrationPing $linear = new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED),
    ) {
    }
}
