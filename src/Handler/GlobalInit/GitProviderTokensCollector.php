<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Handler\InitPromptInputHelper;
use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\Prompt\PromptInterface;

/**
 * Collects GitHub/GitLab tokens for stud config:init (ADR-020).
 */
class GitProviderTokensCollector
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver,
        private readonly InitPromptInputHelper $inputHelper,
        private readonly GitTokenPromptResolver $gitTokenPromptResolver,
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * @return array{GITHUB_TOKEN: ?string, GITLAB_TOKEN: ?string}
     */
    public function collect(GlobalInitPromptContext $context): array
    {
        $gitProviders = $context->gitProviders;
        $existing = $context->existingConfig;
        $collectsGithub = $this->providerResolver->collectsGithub($gitProviders);
        $collectsGitlab = $this->providerResolver->collectsGitlab($gitProviders);

        if (($collectsGithub || $collectsGitlab) && ! $context->isAgent) {
            $context->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.git.title'));
            $context->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, [
                MessageRef::key('config.init.git.description'),
                MessageRef::key('config.init.git.token_help'),
                MessageRef::key('config.init.git.multiple_tokens_note'),
            ]);
        }

        $githubTokenPrompt = MessageRef::key('config.init.git.github_token_prompt', [], 'Enter your GitHub PAT (leave blank to keep existing or skip)');
        $gitlabTokenPrompt = MessageRef::key('config.init.git.gitlab_token_prompt', [], 'Enter your GitLab PAT (leave blank to keep existing or skip)');

        $githubTokenInput = $collectsGithub
            ? ($context->isAgent ? ($context->rawAgentInput['githubToken'] ?? null) : $this->prompt->askHidden($githubTokenPrompt))
            : null;
        $gitlabTokenInput = $collectsGitlab
            ? ($context->isAgent ? ($context->rawAgentInput['gitlabToken'] ?? null) : $this->prompt->askHidden($gitlabTokenPrompt))
            : null;

        return [
            'GITHUB_TOKEN' => $collectsGithub
                ? $this->gitTokenPromptResolver->resolveForGlobalInit(
                    is_string($githubTokenInput) ? $githubTokenInput : null,
                    'GITHUB_TOKEN',
                    $existing,
                    (string) $githubTokenPrompt,
                )
                : $this->inputHelper->nonEmptyStoredString(is_string($existing['GITHUB_TOKEN'] ?? null) ? $existing['GITHUB_TOKEN'] : null),
            'GITLAB_TOKEN' => $collectsGitlab
                ? $this->gitTokenPromptResolver->resolveForGlobalInit(
                    is_string($gitlabTokenInput) ? $gitlabTokenInput : null,
                    'GITLAB_TOKEN',
                    $existing,
                    (string) $gitlabTokenPrompt,
                )
                : $this->inputHelper->nonEmptyStoredString(is_string($existing['GITLAB_TOKEN'] ?? null) ? $existing['GITLAB_TOKEN'] : null),
        ];
    }
}
