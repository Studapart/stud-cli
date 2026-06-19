<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\CommandContextFactory;
use App\Guard\Resolver\ConfigResolver;
use App\Guard\Resolver\EnvironmentResolver;
use App\Guard\Resolver\ProviderContextResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandContextFactoryTest extends TestCase
{
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
        );

        $this->assertSame('develop', $context->projectConfig['baseBranch']);
        $this->assertTrue($context->hasGitRepository);
        $this->assertContains('jira', $context->workItemProviders);
        $this->assertTrue($context->isQuiet);
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
            'WORK_ITEM_PROVIDERS' => ['linear'],
            'LINEAR_API_KEY' => 'key',
        ]);

        $this->assertSame(['linear'], $providers['workItem']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createEvent(array $input): ConsoleCommandEvent
    {
        $command = new Command('items:list');
        $command->addOption('quiet', 'q', InputOption::VALUE_NONE);
        $command->addOption('agent', null, InputOption::VALUE_NONE);

        $input = new ArrayInput($input);
        $input->bind($command->getDefinition());

        return new ConsoleCommandEvent(
            $command,
            $input,
            new BufferedOutput(),
        );
    }
}
