<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Exception\AgentModeException;
use App\Guard\Resolver\CommandInputResolver;
use App\Guard\WorkItemCommandProfileRegistry;
use App\Service\AgentModeHelper;
use App\Service\GitRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class CommandInputResolverInvocationTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentModeHelper::resetCachedAgentInput();
        parent::tearDown();
    }

    public function testResolveInvocationContextReadsIssueProjectAndFilterFields(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => json_encode([
            'key' => 'scil-1',
            'project' => 'sci',
            'filterName' => 'My View',
            'url' => 'https://example.atlassian.net/x',
        ], JSON_THROW_ON_ERROR));
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'items:show', ['key']);

        $context = $resolver->resolveInvocationContext($input, 'items:show');

        $this->assertSame('SCIL-1', $context->issueKey);
        $this->assertSame('SCI', $context->scopeKey);
        $this->assertSame('My View', $context->filterName);
        $this->assertSame('https://example.atlassian.net/x', $context->attachmentUrl);
    }

    public function testResolveInvocationContextReadsBranchIssueKey(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getIssueKeyFromBranchName')->willReturn('SCIL-42');

        $resolver = new CommandInputResolver(gitRepository: $gitRepository);
        $input = $this->createInput([], 'commit');

        $context = $resolver->resolveInvocationContext($input, 'commit');

        $this->assertSame('SCIL-42', $context->branchIssueKey);
    }

    public function testInScopeCommandNamesListsSci197Commands(): void
    {
        $names = WorkItemCommandProfileRegistry::inScopeCommandNames();

        $this->assertContains('items:search', $names);
        $this->assertContains('filters:list', $names);
        $this->assertContains('branch:rename', $names);
    }

    public function testResolveInvocationContextReadsCliOptions(): void
    {
        $resolver = new CommandInputResolver();
        $input = $this->createInput([
            'key' => 'SCI-9',
            '--project' => 'sci',
            'filterName' => 'Board',
            '--url' => 'https://x.test/file',
        ], 'filters:show', ['key']);

        $context = $resolver->resolveInvocationContext($input, 'filters:show');

        $this->assertSame('SCI-9', $context->issueKey);
        $this->assertSame('SCI', $context->scopeKey);
        $this->assertSame('Board', $context->filterName);
        $this->assertSame('https://x.test/file', $context->attachmentUrl);
    }

    public function testPeekAgentPayloadSkipsOutOfScopeCommand(): void
    {
        $helper = new AgentModeHelper(stdinReader: static fn (): string => '{"key":"SCI-1"}');
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'config:validate');

        $context = $resolver->resolveInvocationContext($input, 'config:validate');

        $this->assertNull($context->issueKey);
    }

    public function testPeekAgentPayloadIgnoresInvalidAgentJson(): void
    {
        $helper = $this->createMock(AgentModeHelper::class);
        $helper->method('peekAgentInput')->willThrowException(new AgentModeException('bad json'));
        $resolver = new CommandInputResolver($helper);
        $input = $this->createInput(['--agent' => true], 'items:show', ['key']);

        $context = $resolver->resolveInvocationContext($input, 'items:show');

        $this->assertNull($context->issueKey);
    }

    public function testReadBranchIssueKeyReturnsNullWhenGitRepositoryThrows(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getIssueKeyFromBranchName')->willThrowException(new \RuntimeException('git error'));
        $resolver = new CommandInputResolver(gitRepository: $gitRepository);
        $input = $this->createInput([], 'commit');

        $context = $resolver->resolveInvocationContext($input, 'commit');

        $this->assertNull($context->branchIssueKey);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $arguments
     */
    private function createInput(array $values, string $name, array $arguments = []): ArrayInput
    {
        $command = new Command($name);
        $command->addOption('provider', null, InputOption::VALUE_REQUIRED);
        $command->addOption('agent', null, InputOption::VALUE_NONE);
        $command->addOption('project', 'p', InputOption::VALUE_REQUIRED);
        $command->addOption('url', null, InputOption::VALUE_REQUIRED);
        foreach ($arguments as $argument) {
            $command->addArgument($argument, InputArgument::OPTIONAL);
        }
        $command->addArgument('filterName', InputArgument::OPTIONAL);

        $input = new ArrayInput($values);
        $input->bind($command->getDefinition());

        return $input;
    }
}
