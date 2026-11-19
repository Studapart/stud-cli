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

    /**
     * Checked pings keyed by integration. Git is keyed by its provider and omitted when no provider is known.
     *
     * @return array<string, ConfigValidateIntegrationPing>
     */
    public function checkedIntegrations(?string $gitProvider): array
    {
        $integrations = [];
        if ($this->jira->status !== ConfigValidateResponse::STATUS_SKIPPED) {
            $integrations['jira'] = $this->jira;
        }
        if ($this->linear->status !== ConfigValidateResponse::STATUS_SKIPPED) {
            $integrations['linear'] = $this->linear;
        }
        if ($this->git->status !== ConfigValidateResponse::STATUS_SKIPPED && $gitProvider !== null) {
            $integrations[$gitProvider] = $this->git;
        }

        return $integrations;
    }

    public function hasFailure(): bool
    {
        return $this->jira->status === ConfigValidateResponse::STATUS_FAIL
            || $this->git->status === ConfigValidateResponse::STATUS_FAIL
            || $this->linear->status === ConfigValidateResponse::STATUS_FAIL;
    }
}
