<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\CommandHandlerRegistry;
use App\Handler\ItemListHandler;
use App\Handler\UpdateHandler;
use App\Service\CommandMap;
use PHPUnit\Framework\TestCase;

class CommandHandlerRegistryTest extends TestCase
{
    public function testEveryCommandMapEntryIsRegistered(): void
    {
        $registered = array_keys(CommandHandlerRegistry::entries());
        $commandMapKeys = array_keys(CommandMap::all());

        $this->assertEqualsCanonicalizing($commandMapKeys, $registered);
    }

    public function testAliasResolvesToCanonicalHandler(): void
    {
        $this->assertSame(ItemListHandler::class, CommandHandlerRegistry::handlerClassFor('ls'));
        $this->assertSame('items:list', CommandHandlerRegistry::canonicalName('ls'));
    }

    public function testExplicitCapabilitiesForInlineTask(): void
    {
        $capabilities = CommandHandlerRegistry::capabilitiesFor('confluence:page-labels');

        $this->assertTrue($capabilities->has(ConfluenceAware::class));
    }

    public function testCapabilitiesForDiscoversHandlerMarkers(): void
    {
        $capabilities = CommandHandlerRegistry::capabilitiesFor('items:list');

        $this->assertTrue($capabilities->has(WorkItemJiraAware::class));
    }

    public function testResolveCapabilitiesForInlineTask(): void
    {
        $capabilities = CommandHandlerRegistry::resolveCapabilities('confluence:page-labels');

        $this->assertTrue($capabilities->has(ConfluenceAware::class));
    }

    public function testHandlerDiscoveryForRegisteredCommand(): void
    {
        $capabilities = CommandHandlerRegistry::resolveCapabilities('items:list');

        $this->assertTrue($capabilities->has(WorkItemJiraAware::class));
    }

    public function testUpdateHandlerHasNoCapabilities(): void
    {
        $this->assertSame(UpdateHandler::class, CommandHandlerRegistry::handlerClassFor('update'));
        $this->assertTrue(CommandHandlerRegistry::resolveCapabilities('update')->isEmpty());
    }

    public function testWhitelistedCommands(): void
    {
        $this->assertTrue(CommandHandlerRegistry::isWhitelisted('help'));
        $this->assertTrue(CommandHandlerRegistry::isWhitelisted('cc'));
        $this->assertFalse(CommandHandlerRegistry::isWhitelisted('items:list'));
    }

    public function testUnknownCommandReturnsEmptyCapabilities(): void
    {
        $this->assertNull(CommandHandlerRegistry::handlerClassFor('unknown:command'));
        $this->assertTrue(CommandHandlerRegistry::capabilitiesFor('unknown:command')->isEmpty());
    }
}
