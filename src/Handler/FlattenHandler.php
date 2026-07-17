<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Exception\GitException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Response\CommandResponse;
use App\Service\GitRepository;

class FlattenHandler implements GitRepositoryAware, ProjectBaseBranchAware
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly string $baseBranch,
        mixed $_translator,
    ) {
        unset($_translator);
    }

    public function handle(): CommandResponse
    {
        // 1. Check for clean working directory
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            return CommandResponse::error(MessageRef::key('flatten.error_dirty_working'));
        }

        // 2. Check if there are any fixup commits
        $baseSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $hasFixups = $this->gitRepository->hasFixupCommits($baseSha);

        if (! $hasFixups) {
            return CommandResponse::success(
                messages: [ResponseMessage::notice(MessageRef::key('flatten.no_fixups'))],
            );
        }

        // 3. Warn about history rewrite
        $messages = [ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite'))];

        // 4. Perform the rebase with autosquash
        try {
            $this->gitRepository->rebaseAutosquash($baseSha);

            return CommandResponse::success(MessageRef::key('flatten.success'), messages: $messages);
        } catch (GitException $e) {
            $error = MessageRef::key('flatten.error_rebase', ['error' => $e->getMessage()]);

            return CommandResponse::error(
                $error,
                [ResponseMessage::error($error, $e->getTechnicalDetails())],
            );
        } catch (\Exception $e) {
            return CommandResponse::error(MessageRef::key('flatten.error_rebase', ['error' => $e->getMessage()]));
        }
    }
}
