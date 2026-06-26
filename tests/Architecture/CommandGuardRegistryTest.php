<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitProviderGithubAware;
use App\Guard\Capability\GitProviderGitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\CapabilityDiscovery;
use App\Guard\CommandHandlerRegistry;
use App\Service\CommandMap;
use App\Service\ConfluenceApiClient;
use App\Service\GitHostingPort;
use App\Service\GitRepository;
use App\Service\JiraApiClient;
use PHPUnit\Framework\TestCase;

class CommandGuardRegistryTest extends TestCase
{
    public function testNonWhitelistedCommandsHaveRegistryEntries(): void
    {
        foreach (array_keys(CommandMap::all()) as $commandName) {
            if (CommandHandlerRegistry::isWhitelisted($commandName)) {
                continue;
            }

            $this->assertArrayHasKey(
                $commandName,
                CommandHandlerRegistry::entries(),
                "Missing registry entry for {$commandName}",
            );
        }
    }

    public function testRegisteredHandlerClassesExist(): void
    {
        foreach (CommandHandlerRegistry::entries() as $commandName => $entry) {
            $handlerClass = $entry['handler'];
            if ($handlerClass === null) {
                continue;
            }

            $this->assertTrue(
                class_exists($handlerClass),
                "Handler {$handlerClass} for {$commandName} does not exist",
            );
        }
    }

    public function testHandlerMarkersMatchConstructorDependencies(): void
    {
        $dependencyToMarker = [
            JiraApiClient::class => WorkItemJiraAware::class,
            GitRepository::class => GitRepositoryAware::class,
            GitHostingPort::class => GitProviderGithubAware::class,
            ConfluenceApiClient::class => ConfluenceAware::class,
        ];

        foreach (CommandHandlerRegistry::entries() as $commandName => $entry) {
            if (CommandHandlerRegistry::isWhitelisted($commandName)) {
                continue;
            }

            $handlerClass = $entry['handler'];
            if ($handlerClass === null) {
                continue;
            }

            $constructor = (new \ReflectionClass($handlerClass))->getConstructor();
            if ($constructor === null) {
                continue;
            }

            $capabilities = CapabilityDiscovery::fromClass($handlerClass);
            if ($capabilities->isEmpty()) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();
                if ($typeName === 'string' && $parameter->getName() === 'baseBranch') {
                    $this->assertTrue(
                        $capabilities->has(ProjectBaseBranchAware::class),
                        "{$handlerClass} injects baseBranch but lacks ProjectBaseBranchAware",
                    );
                }

                if (! isset($dependencyToMarker[$typeName])) {
                    continue;
                }

                $expected = $dependencyToMarker[$typeName];
                if ($typeName === GitHostingPort::class) {
                    $this->assertTrue(
                        $capabilities->has(GitProviderGithubAware::class)
                        || $capabilities->has(GitProviderGitlabAware::class),
                        "{$handlerClass} injects GitHostingPort but lacks git provider markers",
                    );

                    continue;
                }

                $this->assertTrue(
                    $capabilities->has($expected),
                    "{$handlerClass} depends on {$typeName} but lacks {$expected}",
                );
            }
        }
    }
}
