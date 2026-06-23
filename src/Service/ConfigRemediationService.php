<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

/**
 * Interactive remediation for missing configuration keys detected by CommandGuard.
 */
class ConfigRemediationService
{
    public function __construct(
        private readonly WorkflowOutput $logger,
        mixed $translator,
        private readonly ?GitBranchService $gitBranchService = null,
    ) {
        unset($translator);
    }

    /**
     * @param array<string> $missingKeys
     * @return array<string, mixed>
     */
    public function promptForMissingKeys(array $missingKeys, string $scope): array
    {
        $values = [];

        foreach ($missingKeys as $key) {
            $autoDetected = $this->autoDetectKey($key);
            if ($autoDetected !== null) {
                $this->logger->addNote(
                    WorkflowOutput::VERBOSITY_NORMAL,
                    MessageRef::key('config.auto_detected', ['key' => $key, 'value' => $autoDetected])
                );
                $values[$key] = $autoDetected;

                continue;
            }

            $prompt = $scope === 'global'
                ? MessageRef::key('config.missing_global_key', ['key' => $key])
                : MessageRef::key('config.missing_project_key', ['key' => $key]);

            $value = $this->logger->ask($prompt, null);
            if ($value !== null && trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    public function autoDetectKey(string $key): ?string
    {
        if ($key === 'baseBranch' && $this->gitBranchService !== null) {
            return $this->autoDetectBaseBranch();
        }

        return null;
    }

    protected function autoDetectBaseBranch(): ?string
    {
        try {
            $candidates = ['develop', 'main', 'master'];
            $remoteBranches = $this->gitBranchService->getAllRemoteBranches('origin');

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $remoteBranches, true)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
