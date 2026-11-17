<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\InitPromptField;
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
    private readonly GitProviderTokenResolver $tokenResolver;

    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver,
        InitPromptInputHelper $inputHelper,
        GitTokenPromptResolver $gitTokenPromptResolver,
        PromptInterface $prompt,
    ) {
        $this->tokenResolver = new GitProviderTokenResolver($inputHelper, $gitTokenPromptResolver, $prompt);
    }

    /**
     * @return array{GITHUB_TOKEN: ?string, GITLAB_TOKEN: ?string}
     */
    public function collect(GlobalInitPromptContext $context): array
    {
        $existing = $context->existingConfig;
        $collectsGithub = $this->providerResolver->collectsGithub($context->gitProviders);
        $collectsGitlab = $this->providerResolver->collectsGitlab($context->gitProviders);

        if (($collectsGithub || $collectsGitlab) && ! $context->isAgent) {
            $context->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.git.title'));
            $context->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, [
                MessageRef::key('config.init.git.description'),
                MessageRef::key('config.init.git.token_help'),
                MessageRef::key('config.init.git.multiple_tokens_note'),
            ]);
        }

        return [
            'GITHUB_TOKEN' => $this->tokenResolver->resolve($collectsGithub, $context, 'GITHUB_TOKEN', new InitPromptField(
                'githubToken',
                $existing['GITHUB_TOKEN'] ?? null,
                MessageRef::key('config.init.git.github_token_prompt', [], 'Enter your GitHub PAT (leave blank to keep existing or skip)'),
            )),
            'GITLAB_TOKEN' => $this->tokenResolver->resolve($collectsGitlab, $context, 'GITLAB_TOKEN', new InitPromptField(
                'gitlabToken',
                $existing['GITLAB_TOKEN'] ?? null,
                MessageRef::key('config.init.git.gitlab_token_prompt', [], 'Enter your GitLab PAT (leave blank to keep existing or skip)'),
            )),
        ];
    }
}
