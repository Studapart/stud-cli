<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\IssueTrackerProvider;
use App\Guard\CommandContextFactory;
use App\Guard\Resolver\CommandInputResolver;
use App\Guard\Resolver\ConfigResolver;
use App\Guard\Resolver\EnvironmentResolver;
use App\Guard\Resolver\ProviderContextResolver;
use App\Service\AgentModeHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandContextFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentModeHelper::resetCachedAgentInput();
        parent::tearDown();
    }

    public function testCreateBuildsContextFromConfigAndEvent(): void
    {
        $event = $this->createEvent(['--quiet' => true]);
        $factory = new CommandContextFactory();

        $context = $factory->create(
            $event,
            [
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            ['baseBranch' => 'develop'],
            true,
            null,
            'items:list',
        );

        $this->assertSame('develop', $context->projectConfig['baseBranch']);
        $this->assertTrue($context->hasGitRepository);
        $this->assertContains('jira', $context->issueTrackerProviders);
        $this->assertTrue($context->isQuiet);
    }

    public function testCreateResolvesProviderFromIssueKeyForShowCommand(): void
    {
        $event = $this->createEvent(['key' => 'SCIL-99'], 'items:show', true);
        $factory = new CommandContextFactory();

        $context = $factory->create(
            $event,
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            [
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'SCIL',
            ],
            true,
            null,
            'items:show',
        );

        $this->assertSame(['linear'], $context->issueTrackerProviders);
        $this->assertNull($context->providerResolutionBlock);
    }

    public function testCreateBlocksSearchUnderDualPmWithoutOverride(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"jql":"find me"}');
        $factory = new CommandContextFactory(new \App\Guard\Resolver\ConfigResolver(), new \App\Guard\Resolver\EffectiveProviderResolver(), new \App\Guard\Resolver\IssueTrackerProviderResolver(), new CommandInputResolver($helper));
        $event = $this->createEvent(['--agent' => true], 'items:search', true);

        $context = $factory->create(
            $event,
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value, 'projectKey' => 'SCI', 'linearTeamKey' => 'SCIL'],
            true,
            null,
            'items:search',
        );

        $this->assertNotNull($context->providerResolutionBlock);
        $this->assertSame('issue_tracker_provider.search_requires_explicit_provider', $context->providerResolutionBlock->key);
    }

    public function testCreateFallsBackForNonProfileCommand(): void
    {
        $event = $this->createEvent([], 'help');
        $factory = new CommandContextFactory();

        $context = $factory->create(
            $event,
            ['ISSUE_TRACKER_PROVIDERS' => ['jira'], 'JIRA_URL' => 'x', 'JIRA_EMAIL' => 'e', 'JIRA_API_TOKEN' => 't'],
            null,
            false,
            null,
            'help',
        );

        $this->assertSame(['jira'], $context->issueTrackerProviders);
        $this->assertNull($context->providerResolution);
    }

    public function testConfigResolverReturnsProvidedConfig(): void
    {
        $resolver = new ConfigResolver();
        $result = $resolver->resolve(['JIRA_URL' => 'x'], ['baseBranch' => 'main']);

        $this->assertSame('x', $result['global']['JIRA_URL']);
        $this->assertSame('main', $result['project']['baseBranch']);
    }

    public function testEnvironmentResolverFlags(): void
    {
        $event = $this->createEvent(['--agent' => true]);
        $resolver = EnvironmentResolver::fromEvent($event, false);

        $flags = $resolver->resolveFlags($event->getInput());

        $this->assertFalse($resolver->hasGitRepository());
        $this->assertTrue($flags['agent']);
    }

    public function testProviderContextResolverUsesGlobalConfig(): void
    {
        $resolver = new ProviderContextResolver();
        $providers = $resolver->resolve([
            'ISSUE_TRACKER_PROVIDERS' => ['linear'],
            'LINEAR_API_KEY' => 'key',
        ]);

        $this->assertSame(['linear'], $providers['workItem']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createEvent(array $input, string $name = 'items:list', bool $withKey = false): ConsoleCommandEvent
    {
        $command = new Command($name);
        $command->addOption('quiet', 'q', InputOption::VALUE_NONE);
        $command->addOption('agent', null, InputOption::VALUE_NONE);
        if ($withKey) {
            $command->addArgument('key', InputArgument::OPTIONAL);
            $command->addArgument('jql', InputArgument::OPTIONAL);
        }

        $input = new ArrayInput($input);
        $input->bind($command->getDefinition());

        return new ConsoleCommandEvent(
            $command,
            $input,
            new BufferedOutput(),
        );
    }
}
