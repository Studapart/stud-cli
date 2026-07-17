<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Enum\WorkItemCommandProfile;
use App\Exception\AgentModeException;
use App\Guard\CommandHandlerRegistry;
use App\Guard\DTO\CommandInvocationContext;
use App\Guard\WorkItemCommandProfileRegistry;
use App\Service\AgentModeHelper;
use App\Service\AgentModeSchemaGenerator;
use App\Service\GitRepository;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Reads per-invocation CLI and agent inputs needed before the readiness guard runs.
 */
final class CommandInputResolver
{
    /** @var list<string> */
    private const AGENT_INPUT_FILE_ARGUMENTS = [
        'inputFile',
        'key',
        'jql',
        'filterName',
        'branch',
        'message',
        'commandName',
        'version',
    ];

    public function __construct(
        private readonly AgentModeHelper $agentModeHelper = new AgentModeHelper(),
        private readonly ?GitRepository $gitRepository = null,
    ) {
    }

    public function resolveInvocationContext(InputInterface $input, string $commandName): CommandInvocationContext
    {
        $overrideResolution = $this->resolveIssueTrackerProviderOverride($input, $commandName);
        $profile = WorkItemCommandProfileRegistry::forCommand($commandName);
        $agentPayload = $this->peekAgentPayload($input, $commandName);

        $issueKey = $this->readIssueKey($input, $agentPayload);
        $scopeKey = $this->readScopeKey($input, $agentPayload);
        $filterName = $this->readFilterName($input, $agentPayload);
        $attachmentUrl = $this->readAttachmentUrl($input, $agentPayload);
        $branchIssueKey = $this->readBranchIssueKey($profile, $issueKey);

        return new CommandInvocationContext(
            providerOverride: $overrideResolution['override'],
            providerOverrideError: $overrideResolution['error'],
            issueKey: $issueKey,
            scopeKey: $scopeKey,
            filterName: $filterName,
            branchIssueKey: $branchIssueKey,
            attachmentUrl: $attachmentUrl,
        );
    }

    /**
     * @return array{override: ?string, error: ?MessageRef}
     */
    public function resolveIssueTrackerProviderOverride(InputInterface $input, string $commandName): array
    {
        if ($input->hasOption('provider')) {
            $cliProvider = $input->getOption('provider');
            if (is_string($cliProvider) && trim($cliProvider) !== '') {
                return $this->normalizeOverride(trim($cliProvider));
            }
        }

        if (! $input->hasOption('agent') || ! (bool) $input->getOption('agent')) {
            return ['override' => null, 'error' => null];
        }

        $canonical = CommandHandlerRegistry::canonicalName($commandName);
        if (! in_array($canonical, AgentModeSchemaGenerator::commandsWithProviderOverride(), true)) {
            return ['override' => null, 'error' => null];
        }

        $agentProvider = $this->readProviderFromAgentInput($input);
        if ($agentProvider === null) {
            return ['override' => null, 'error' => null];
        }

        return $this->normalizeOverride($agentProvider);
    }

    /**
     * @return array{override: ?string, error: ?MessageRef}
     */
    private function normalizeOverride(string $raw): array
    {
        $normalized = strtolower(trim($raw));
        if ($normalized === IssueTrackerProvider::Auto->value) {
            return [
                'override' => null,
                'error' => MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => $raw]),
            ];
        }

        $provider = IssueTrackerProvider::tryFromNormalized($normalized);
        if ($provider === null || $provider->isAuto()) {
            return [
                'override' => null,
                'error' => MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => $raw]),
            ];
        }

        return ['override' => $provider->vendorSlug(), 'error' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function peekAgentPayload(InputInterface $input, string $commandName): ?array
    {
        if (! $input->hasOption('agent') || ! (bool) $input->getOption('agent')) {
            return null;
        }

        $canonical = CommandHandlerRegistry::canonicalName($commandName);
        if (! in_array($canonical, WorkItemCommandProfileRegistry::inScopeCommandNames(), true)) {
            return null;
        }

        $inputFile = $this->resolveAgentInputFile($input);

        try {
            return $this->agentModeHelper->peekAgentInput($inputFile);
        } catch (AgentModeException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $agentPayload
     */
    private function readIssueKey(InputInterface $input, ?array $agentPayload): ?string
    {
        if ($agentPayload !== null) {
            foreach (['key', 'issueKey'] as $field) {
                $value = $agentPayload[$field] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return strtoupper(trim($value));
                }
            }
        }

        if ($input->hasArgument('key')) {
            $value = $input->getArgument('key');
            if (is_string($value) && trim($value) !== '' && ! is_readable($value)) {
                return strtoupper(trim($value));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $agentPayload
     */
    private function readScopeKey(InputInterface $input, ?array $agentPayload): ?string
    {
        if ($agentPayload !== null) {
            $value = $agentPayload['project'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        }

        if ($input->hasOption('project')) {
            $value = $input->getOption('project');
            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $agentPayload
     */
    private function readFilterName(InputInterface $input, ?array $agentPayload): ?string
    {
        if ($agentPayload !== null) {
            $value = $agentPayload['filterName'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($input->hasArgument('filterName')) {
            $value = $input->getArgument('filterName');
            if (is_string($value) && trim($value) !== '' && ! is_readable($value)) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $agentPayload
     */
    private function readAttachmentUrl(InputInterface $input, ?array $agentPayload): ?string
    {
        if ($agentPayload !== null) {
            $value = $agentPayload['url'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($input->hasOption('url')) {
            $value = $input->getOption('url');
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function readBranchIssueKey(?WorkItemCommandProfile $profile, ?string $issueKey): ?string
    {
        if ($profile !== WorkItemCommandProfile::KeyOrBranch || $issueKey !== null || $this->gitRepository === null) {
            return null;
        }

        try {
            $key = $this->gitRepository->getIssueKeyFromBranchName();
        } catch (\Throwable) {
            return null;
        }

        return is_string($key) && trim($key) !== '' ? strtoupper(trim($key)) : null;
    }

    private function readProviderFromAgentInput(InputInterface $input): ?string
    {
        $inputFile = $this->resolveAgentInputFile($input);

        try {
            $decoded = $this->agentModeHelper->peekAgentInput($inputFile);
        } catch (AgentModeException) {
            return null;
        }

        if (! isset($decoded['provider']) || ! is_string($decoded['provider'])) {
            return null;
        }

        return $decoded['provider'];
    }

    private function resolveAgentInputFile(InputInterface $input): ?string
    {
        foreach (self::AGENT_INPUT_FILE_ARGUMENTS as $argumentName) {
            if (! $input->hasArgument($argumentName)) {
                continue;
            }

            $value = $input->getArgument($argumentName);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (is_readable($value)) {
                return $value;
            }
        }

        return null;
    }
}
