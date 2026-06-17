<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Enum\GlobalGitProviderMenu;
use App\Enum\GlobalWorkItemProviderMenu;
use App\Handler\GlobalInit\GitProviderTokensCollector;
use App\Handler\GlobalInit\GlobalInitPromptContext;
use App\Handler\GlobalInit\JiraCredentialsCollector;
use App\Handler\GlobalInit\LinearApiKeyCollector;
use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\MessageRenderer;
use App\Service\Prompt\PromptInterface;

/**
 * Director for stud config:init prompts — language, provider menus, provider strategies (ADR-020).
 */
class InitPromptCollector
{
    private InitPromptInputHelper $inputHelper;
    private JiraCredentialsCollector $jiraCredentialsCollector;
    private LinearApiKeyCollector $linearApiKeyCollector;
    private GitProviderTokensCollector $gitProviderTokensCollector;

    public function __construct(
        private readonly PromptInterface $prompt,
        GitTokenPromptResolver $gitTokenPromptResolver,
        private readonly MessageRenderer $messageRenderer,
        private readonly GlobalConfigProviderResolver $providerResolver,
        ?InitPromptInputHelper $inputHelper = null,
        ?JiraCredentialsCollector $jiraCredentialsCollector = null,
        ?LinearApiKeyCollector $linearApiKeyCollector = null,
        ?GitProviderTokensCollector $gitProviderTokensCollector = null,
    ) {
        $this->inputHelper = $inputHelper ?? new InitPromptInputHelper($prompt);
        $this->jiraCredentialsCollector = $jiraCredentialsCollector ?? new JiraCredentialsCollector($providerResolver, $this->inputHelper, $prompt);
        $this->linearApiKeyCollector = $linearApiKeyCollector ?? new LinearApiKeyCollector($providerResolver, $this->inputHelper);
        $this->gitProviderTokensCollector = $gitProviderTokensCollector ?? new GitProviderTokensCollector(
            $providerResolver,
            $this->inputHelper,
            $gitTokenPromptResolver,
            $prompt,
        );
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @param array<string, mixed> $rawAgentInput
     * @return array<string, mixed>
     */
    public function buildGlobalConfig(
        array $existingConfig,
        array $rawAgentInput,
        bool $isAgent,
        WorkflowEntryRecorder $recorder,
    ): array {
        $languageChoice = $this->promptLanguage($existingConfig, $recorder);

        $gitProviders = $this->resolveGitProviders($existingConfig, $rawAgentInput, $isAgent, $recorder);
        $workItemProviders = $this->resolveWorkItemProviders($existingConfig, $rawAgentInput, $isAgent, $recorder);

        $context = new GlobalInitPromptContext(
            $existingConfig,
            $rawAgentInput,
            $isAgent,
            $recorder,
            $gitProviders,
            $workItemProviders,
        );

        return [
            'LANGUAGE' => $languageChoice,
            'GIT_PROVIDERS' => $gitProviders,
            'WORK_ITEM_PROVIDERS' => $workItemProviders,
            ...$this->jiraCredentialsCollector->collect($context),
            ...$this->linearApiKeyCollector->collect($context),
            ...$this->gitProviderTokensCollector->collect($context),
            'JIRA_TRANSITION_ENABLED' => $this->jiraCredentialsCollector->collectTransitionEnabled($context),
        ];
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @param array<string, mixed> $rawAgentInput
     * @return list<string>
     */
    protected function resolveGitProviders(
        array $existingConfig,
        array $rawAgentInput,
        bool $isAgent,
        WorkflowEntryRecorder $recorder,
    ): array {
        if ($isAgent && isset($rawAgentInput['gitProviders']) && is_array($rawAgentInput['gitProviders'])) {
            $providers = array_values(array_filter($rawAgentInput['gitProviders'], 'is_string'));

            return $this->providerResolver->normalizeGitProviders($providers);
        }

        return $this->promptGitProviders($existingConfig, $recorder);
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @param array<string, mixed> $rawAgentInput
     * @return list<string>
     */
    protected function resolveWorkItemProviders(
        array $existingConfig,
        array $rawAgentInput,
        bool $isAgent,
        WorkflowEntryRecorder $recorder,
    ): array {
        if ($isAgent && isset($rawAgentInput['workItemProviders']) && is_array($rawAgentInput['workItemProviders'])) {
            $providers = array_values(array_filter($rawAgentInput['workItemProviders'], 'is_string'));

            return $this->providerResolver->normalizeWorkItemProviders($providers);
        }

        return $this->promptWorkItemProviders($existingConfig, $recorder);
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return list<string>
     */
    protected function promptGitProviders(array $existingConfig, WorkflowEntryRecorder $recorder): array
    {
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.git_provider.title'));
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.git_provider.menu'));

        $defaultMenu = GlobalGitProviderMenu::fromProviderValues(
            $this->providerResolver->inferDefaultGitProviders($existingConfig)
        );
        $choices = [];
        foreach (GlobalGitProviderMenu::orderedCases() as $case) {
            $choices[] = (string) $this->messageRenderer->render(MessageRef::key($case->choiceMessageKey()));
        }
        $choice = $this->prompt->choice(
            MessageRef::key('config.init.git_provider.prompt'),
            $choices,
            $this->messageRenderer->render(MessageRef::key($defaultMenu->choiceMessageKey())),
        );

        return GlobalGitProviderMenu::fromRenderedChoice((string) $choice, $this->messageRenderer)->toProviderValues();
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return list<string>
     */
    protected function promptWorkItemProviders(array $existingConfig, WorkflowEntryRecorder $recorder): array
    {
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.work_item_provider.title'));
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.work_item_provider.menu'));

        $defaultMenu = GlobalWorkItemProviderMenu::fromProviderValues(
            $this->providerResolver->inferDefaultWorkItemProviders($existingConfig)
        );
        $choices = [];
        foreach (GlobalWorkItemProviderMenu::orderedCases() as $case) {
            $choices[] = (string) $this->messageRenderer->render(MessageRef::key($case->choiceMessageKey()));
        }
        $choice = $this->prompt->choice(
            MessageRef::key('config.init.work_item_provider.prompt'),
            $choices,
            $this->messageRenderer->render(MessageRef::key($defaultMenu->choiceMessageKey())),
        );

        return GlobalWorkItemProviderMenu::fromRenderedChoice((string) $choice, $this->messageRenderer)->toProviderValues();
    }

    /**
     * @param array<string, mixed> $existingConfig
     */
    protected function promptLanguage(array $existingConfig, WorkflowEntryRecorder $recorder): string
    {
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.language.title'));
        $availableLanguages = ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'nl' => 'Dutch', 'ru' => 'Russian', 'el' => 'Greek', 'af' => 'Afrikaans', 'vi' => 'Vietnamese'];
        $defaultLanguage = $existingConfig['LANGUAGE'] ?? $this->detectSystemLocale() ?? 'en';

        $languageOptions = [];
        $languageMap = [];
        foreach ($availableLanguages as $code => $name) {
            $display = "{$name} ({$code})";
            $languageOptions[] = $display;
            $languageMap[$display] = $code;
        }

        $defaultDisplay = $availableLanguages[$defaultLanguage] . ' (' . $defaultLanguage . ')';

        $languageChoiceDisplay = $this->prompt->choice(
            MessageRef::key('config.init.language.prompt'),
            $languageOptions,
            $defaultDisplay
        );

        return $languageMap[$languageChoiceDisplay];
    }

    /**
     * @return string|null The detected language code (e.g., 'fr', 'es') or null if not detected/unsupported
     */
    protected function detectSystemLocale(): ?string
    {
        $supportedLanguages = ['en', 'fr', 'es', 'nl', 'ru', 'el', 'af', 'vi'];

        $locale = getenv('LC_ALL') ?: getenv('LANG');
        if ($locale === false || $locale === '') {
            return null;
        }

        $parts = explode('.', (string) $locale);
        $languagePart = $parts[0];
        $languageCode = explode('_', $languagePart)[0];
        $languageCode = strtolower($languageCode);

        if (in_array($languageCode, $supportedLanguages, true)) {
            return $languageCode;
        }

        return null;
    }
}
