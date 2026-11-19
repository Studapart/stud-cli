<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\SecretKeyPolicy;
use App\Response\ConfigProjectInitResponse;
use App\Service\GitRepository;
use App\Service\GitSetupService;

/**
 * Merges validated YAML patches into the project config, checking a patched base branch on the remote first.
 */
class ConfigProjectInitPatchWriter
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitSetupService $gitSetupService,
    ) {
    }

    /**
     * @param array<string, mixed> $yamlPatches
     */
    public function write(array $yamlPatches, bool $skipBaseBranchRemoteCheck): ConfigProjectInitResponse
    {
        if ($yamlPatches === []) {
            return ConfigProjectInitResponse::success(false, SecretKeyPolicy::redact($this->gitRepository->readProjectConfig()));
        }

        if (isset($yamlPatches['baseBranch']) && ! $skipBaseBranchRemoteCheck) {
            try {
                $this->gitSetupService->validateBaseBranchOnRemote((string) $yamlPatches['baseBranch']);
            } catch (\RuntimeException $e) {
                return ConfigProjectInitResponse::error('config.project_init.base_branch_error', [
                    '%message%' => $e->getMessage(),
                ]);
            }
        }

        $merged = $this->gitRepository->readProjectConfig();
        foreach ($yamlPatches as $key => $value) {
            $merged[$key] = $value;
        }
        $this->gitRepository->writeProjectConfig($merged);

        return ConfigProjectInitResponse::success(true, SecretKeyPolicy::redact($this->gitRepository->readProjectConfig()));
    }
}
