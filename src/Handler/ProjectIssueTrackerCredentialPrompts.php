<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\EffectiveIssueTrackerCredentials;
use App\Config\GlobalStudConfigKeys;
use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

/**
 * Asks for project issue-tracker credentials. A blank answer leaves the stored value unchanged.
 */
final class ProjectIssueTrackerCredentialPrompts
{
    public function __construct(
        private readonly PromptInterface $prompt,
        private readonly EffectiveIssueTrackerCredentials $credentials = new EffectiveIssueTrackerCredentials(),
    ) {
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    public function jiraPatches(array $existing): array
    {
        return array_merge(
            $this->visible('jiraUrl', GlobalStudConfigKeys::JIRA_URL, 'config.project_init.prompt_jira_url', $existing),
            $this->visible('jiraEmail', GlobalStudConfigKeys::JIRA_EMAIL, 'config.project_init.prompt_jira_email', $existing),
            $this->hidden('jiraApiToken', 'config.project_init.prompt_jira_api_token'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function linearPatches(): array
    {
        return $this->hidden('linearApiKey', 'config.project_init.prompt_linear_api_key');
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array<string, string>
     */
    private function visible(string $inputKey, string $yamlKey, string $messageKey, array $existing): array
    {
        $current = isset($existing[$yamlKey]) && is_string($existing[$yamlKey]) ? trim($existing[$yamlKey]) : '';
        $answer = $this->prompt->ask(MessageRef::key($messageKey), $current !== '' ? $current : null);
        if ($answer === null) {
            return [];
        }

        $stored = $this->credentials->normalizeInputPatches([$inputKey => $answer]);

        return isset($stored[$inputKey]) && is_string($stored[$inputKey]) ? [$inputKey => $stored[$inputKey]] : [];
    }

    /**
     * @return array<string, string>
     */
    private function hidden(string $inputKey, string $messageKey): array
    {
        $answer = $this->prompt->askHidden(MessageRef::key($messageKey));
        if ($answer === null) {
            return [];
        }

        $stored = $this->credentials->normalizeInputPatches([$inputKey => $answer]);

        return isset($stored[$inputKey]) && is_string($stored[$inputKey]) ? [$inputKey => $stored[$inputKey]] : [];
    }
}
