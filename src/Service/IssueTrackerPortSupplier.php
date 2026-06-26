<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Exception\IssueTrackerException;

/**
 * Resolves the active work-item provider and builds the matching {@see IssueTrackerPort}.
 *
 * Keeps HTTP clients out of handlers; wired once in castor.php.
 */
class IssueTrackerPortSupplier
{
    public function __construct(
        private readonly IssueTrackerFactory $factory,
        private readonly IssueTrackerResolver $resolver,
        private readonly ?JiraApiClient $jiraApiClient,
        private readonly ?JiraAttachmentService $attachmentService,
        private readonly ?LinearApiClient $linearApiClient,
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear', port: IssueTrackerPort}|array{ok: false, error: MessageRef}
     */
    public function resolve(array $globalConfig, array $projectConfig): array
    {
        $resolution = $this->resolver->resolveActiveProvider($globalConfig, $projectConfig);
        if (! $resolution['ok']) {
            return $resolution;
        }

        try {
            return [
                'ok' => true,
                'provider' => $resolution['provider'],
                'port' => $this->factory->createForProvider(
                    $resolution['provider'],
                    $this->jiraApiClient,
                    $this->attachmentService,
                    $this->linearApiClient,
                ),
            ];
        } catch (IssueTrackerException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }
    }
}
