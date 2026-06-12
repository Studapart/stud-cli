<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\WorkflowOutput;

class DeployHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly string $baseBranch,
        mixed $_translator,
        private readonly ?WorkflowOutput $logger = null,
    ) {
        unset($_translator);
    }

    public function handle(): CommandResponse
    {
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        if (! str_starts_with($currentBranch, 'release/v')) {
            return CommandResponse::error(MessageRef::key('deploy.error_not_release'));
        }

        $version = str_replace('release/v', '', $currentBranch);

        // Deploy to main
        $this->gitRepository->checkout('main');
        $this->gitRepository->pull('origin', 'main');
        $this->gitRepository->merge($currentBranch);
        $this->gitRepository->tag('v' . $version, 'Release v' . $version);
        $this->gitRepository->pushTags('origin');

        // Update base branch
        $baseBranchName = str_replace('origin/', '', $this->baseBranch);
        $this->gitRepository->checkout($baseBranchName);
        $this->gitRepository->pull('origin', $baseBranchName);
        $this->gitRepository->rebase('main');
        $this->gitRepository->forcePushWithLeaseRemote('origin', $baseBranchName);

        $messages = $this->cleanupReleaseBranch($currentBranch);

        return CommandResponse::success(
            MessageRef::key('deploy.success', ['version' => $version]),
            ['version' => $version, 'branch' => $currentBranch, 'baseBranch' => $baseBranchName],
            $messages,
        );
    }

    /**
     * @return list<ResponseMessage>
     */
    private function cleanupReleaseBranch(string $branchName): array
    {
        $messages = [];
        if ($this->gitRepository->localBranchExists($branchName)) {
            $messages = array_merge($messages, $this->deleteLocalBranch($branchName));
        }

        if ($this->gitRepository->remoteBranchExists('origin', $branchName)) {
            $this->gitRepository->deleteRemoteBranch('origin', $branchName);
        }

        return $messages;
    }

    /**
     * @return list<ResponseMessage>
     */
    private function deleteLocalBranch(string $branchName): array
    {
        $remoteExists = $this->gitRepository->remoteBranchExists('origin', $branchName);

        try {
            $this->gitRepository->deleteBranch($branchName, $remoteExists);

            return [];
        } catch (\Exception $e) {
            if ($remoteExists) {
                return [$this->buildWarningResponse('deploy.warning_branch_cleanup', [
                    'branch' => $branchName,
                    'error' => $e->getMessage(),
                ])];
            }
        }

        try {
            $this->gitRepository->deleteBranchForce($branchName);

            return [$this->buildWarningResponse('branches.clean.force_delete_warning', ['branch' => $branchName])];
        } catch (\Exception $forceException) {
            return [$this->buildWarningResponse('deploy.warning_branch_cleanup', [
                'branch' => $branchName,
                'error' => $forceException->getMessage(),
            ])];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildWarningResponse(string $key, array $params): ResponseMessage
    {
        $message = MessageRef::key($key, $params);
        $this->logger?->addWarning(WorkflowOutput::VERBOSITY_NORMAL, $message);

        return ResponseMessage::warning($message);
    }
}
