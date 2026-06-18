<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Handler\InitPromptInputHelper;
use App\Service\GlobalConfigProviderResolver;
use App\Service\Prompt\PromptInterface;

/**
 * Collects Jira URL, email, API token, and transition flag for stud config:init (ADR-020).
 */
class JiraCredentialsCollector
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver,
        private readonly InitPromptInputHelper $inputHelper,
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * @return array{JIRA_URL: ?string, JIRA_EMAIL: ?string, JIRA_API_TOKEN: ?string}
     */
    public function collect(GlobalInitPromptContext $context): array
    {
        $active = $this->providerResolver->collectsJira($context->workItemProviders);
        $existing = $context->existingConfig;

        if ($active && ! $context->isAgent) {
            $context->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.jira.title'));
            $context->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.jira.token_help'));
        }

        $jiraUrlPrompt = MessageRef::key('config.init.jira.url_prompt', [], 'Enter your Jira URL');
        $jiraEmailPrompt = MessageRef::key('config.init.jira.email_prompt', [], 'Enter your Jira email address');
        $jiraTokenPrompt = MessageRef::key('config.init.jira.token_prompt', [], 'Enter your Jira API token (leave blank to keep existing)');

        return [
            'JIRA_URL' => $this->inputHelper->resolveWhenActive(
                $active,
                $context->isAgent,
                $context->rawAgentInput,
                'jiraUrl',
                $existing['JIRA_URL'] ?? null,
                $jiraUrlPrompt,
                hidden: false,
                normalize: fn (string $s): string => $this->normalizeJiraUrl($s),
            ),
            'JIRA_EMAIL' => $this->inputHelper->resolveWhenActive(
                $active,
                $context->isAgent,
                $context->rawAgentInput,
                'jiraEmail',
                $existing['JIRA_EMAIL'] ?? null,
                $jiraEmailPrompt,
            ),
            'JIRA_API_TOKEN' => $this->inputHelper->resolveWhenActive(
                $active,
                $context->isAgent,
                $context->rawAgentInput,
                'jiraApiToken',
                $existing['JIRA_API_TOKEN'] ?? null,
                $jiraTokenPrompt,
                hidden: true,
            ),
        ];
    }

    public function collectTransitionEnabled(GlobalInitPromptContext $context): bool
    {
        $active = $this->providerResolver->collectsJira($context->workItemProviders);
        $existing = $context->existingConfig;

        if (! $active) {
            return (bool) ($existing['JIRA_TRANSITION_ENABLED'] ?? false);
        }

        if ($context->isAgent) {
            return (bool) ($context->rawAgentInput['jiraTransitionEnabled'] ?? ($existing['JIRA_TRANSITION_ENABLED'] ?? false));
        }

        return $this->promptJiraTransitionEnabled($existing, $context->recorder);
    }

    /**
     * @param array<string, mixed> $existingConfig
     */
    protected function promptJiraTransitionEnabled(array $existingConfig, WorkflowEntryRecorder $recorder): bool
    {
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.jira_transition.title'));
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.jira_transition.description'));

        return $this->prompt->confirm(
            MessageRef::key('config.init.jira_transition.prompt'),
            $existingConfig['JIRA_TRANSITION_ENABLED'] ?? false
        );
    }

    protected function normalizeJiraUrl(string $url): string
    {
        return rtrim($url, '/');
    }
}
