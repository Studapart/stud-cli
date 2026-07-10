<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Exception\AgentModeException;
use App\Guard\CommandHandlerRegistry;
use App\Service\AgentModeHelper;
use App\Service\AgentModeSchemaGenerator;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Reads per-invocation CLI and agent inputs needed before the readiness guard runs.
 */
final class CommandInputResolver
{
    /** @var list<string> */
    private const AGENT_INPUT_FILE_ARGUMENTS = ['inputFile', 'key', 'jql', 'filterName', 'branch', 'message', 'commandName', 'version'];

    public function __construct(
        private readonly AgentModeHelper $agentModeHelper = new AgentModeHelper(),
    ) {
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
        if (! in_array($canonical, AgentModeSchemaGenerator::itemsCommandsWithProviderOverride(), true)) {
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
