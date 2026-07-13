<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\IssueTrackerProvider;
use App\Guard\Resolver\CommandInputResolver;
use App\Guard\Resolver\EffectiveProviderResolver;
use App\Service\AgentModeHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CommandInputResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentModeHelper::resetCachedAgentInput();
        parent::tearDown();
    }

    public function testResolveUsesCliProviderOverrideCaseInsensitively(): void
    {
        $resolver = new CommandInputResolver();
        $input = $this->createInput(['--provider' => 'LINEAR'], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertSame(IssueTrackerProvider::Linear->value, $result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveRejectsAutoOverride(): void
    {
        $resolver = new CommandInputResolver();
        $input = $this->createInput(['--provider' => 'auto'], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertNull($result['override']);
        $this->assertNotNull($result['error']);
    }

    public function testResolveReadsProviderFromAgentJson(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"provider":"jira"}');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertSame(IssueTrackerProvider::Jira->value, $result['override']);
    }

    public function testDualAutoAggregateClearsAmbiguityForListCommand(): void
    {
        $resolver = new EffectiveProviderResolver();
        $result = $resolver->resolveIssueTrackerProviders(
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            null,
            true,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame(['jira', 'linear'], $result['providers']);
    }

    public function testProviderOverrideClearsAmbiguity(): void
    {
        $resolver = new EffectiveProviderResolver();
        $result = $resolver->resolveIssueTrackerProviders(
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            IssueTrackerProvider::Linear->value,
            false,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame(['linear'], $result['providers']);
    }

    public function testResolveReturnsNullWhenAgentModeDisabled(): void
    {
        $resolver = new CommandInputResolver();
        $input = $this->createInput([], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertNull($result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveReadsProviderFromAgentJsonForCommitCommand(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"provider":"jira"}');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'commit');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'commit');

        $this->assertSame(IssueTrackerProvider::Jira->value, $result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveSkipsAgentProviderForNonWorkItemCommand(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"provider":"jira"}');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'config:validate');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'config:validate');

        $this->assertNull($result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveReturnsNullWhenAgentJsonOmitsProvider(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"key":"SCI-1"}');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertNull($result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveRejectsUnknownProviderOverride(): void
    {
        $resolver = new CommandInputResolver();
        $input = $this->createInput(['--provider' => 'bogus'], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertNull($result['override']);
        $this->assertNotNull($result['error']);
    }

    public function testResolveIgnoresInvalidAgentJson(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => 'not-json');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertNull($result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveIgnoresNonStringProviderInAgentJson(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"provider":123}');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        $this->assertNull($result['override']);
        $this->assertNull($result['error']);
    }

    public function testResolveReadsProviderFromAgentInputFileArgument(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stud_agent_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '{"provider":"linear"}');

        $resolver = new CommandInputResolver();
        $input = $this->createInput(['--agent' => true, 'inputFile' => $tmp], 'items:list');

        $result = $resolver->resolveIssueTrackerProviderOverride($input, 'items:list');

        @unlink($tmp);

        $this->assertSame(IssueTrackerProvider::Linear->value, $result['override']);
        $this->assertNull($result['error']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createInput(array $values, string $name): ArrayInput
    {
        $command = new Command($name);
        $command->addOption('provider', null, InputOption::VALUE_REQUIRED);
        $command->addOption('agent', null, InputOption::VALUE_NONE);
        $command->addArgument('inputFile', InputArgument::OPTIONAL);
        $input = new ArrayInput($values);
        $input->bind($command->getDefinition());

        return $input;
    }
}
