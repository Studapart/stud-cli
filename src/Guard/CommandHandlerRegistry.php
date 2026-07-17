<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Capability\ConfluenceAware;
use App\Handler\BranchCleanHandler;
use App\Handler\BranchListHandler;
use App\Handler\BranchRenameHandler;
use App\Handler\BranchSwitchHandler;
use App\Handler\CacheClearHandler;
use App\Handler\CommitHandler;
use App\Handler\CommitUndoHandler;
use App\Handler\ConfigProjectInitHandler;
use App\Handler\ConfigShowHandler;
use App\Handler\ConfigValidateHandler;
use App\Handler\ConfluencePushHandler;
use App\Handler\ConfluenceShowHandler;
use App\Handler\DeployHandler;
use App\Handler\FilterListHandler;
use App\Handler\FilterShowHandler;
use App\Handler\FlattenHandler;
use App\Handler\InitHandler;
use App\Handler\ItemCreateHandler;
use App\Handler\ItemDownloadHandler;
use App\Handler\ItemListHandler;
use App\Handler\ItemShowHandler;
use App\Handler\ItemStartHandler;
use App\Handler\ItemTakeoverHandler;
use App\Handler\ItemTransitionHandler;
use App\Handler\ItemUpdateHandler;
use App\Handler\ItemUploadHandler;
use App\Handler\PleaseHandler;
use App\Handler\PrCommentHandler;
use App\Handler\PrCommentsHandler;
use App\Handler\ProjectListHandler;
use App\Handler\ProjectsLabelsHandler;
use App\Handler\ProjectsWorkflowHandler;
use App\Handler\PushHandler;
use App\Handler\ReleaseHandler;
use App\Handler\SearchHandler;
use App\Handler\StatusHandler;
use App\Handler\SubmitHandler;
use App\Handler\SyncHandler;
use App\Handler\UpdateHandler;
use App\Service\CommandMap;

/**
 * Maps canonical command names to handler classes and explicit capability overrides.
 */
class CommandHandlerRegistry
{
    /** @var list<string> */
    public const WHITELISTED_COMMANDS = [
        'config:init',
        'init',
        'config:show',
        'config:project-init',
        'cpi',
        'help',
        'main',
        'cache:clear',
        'cc',
    ];

    /**
     * @var array<string, array{handler: class-string|null, capabilities: list<class-string>|null}>
     */
    private const ENTRIES = [
        'config:init' => ['handler' => InitHandler::class, 'capabilities' => null],
        'config:show' => ['handler' => ConfigShowHandler::class, 'capabilities' => null],
        'config:validate' => ['handler' => ConfigValidateHandler::class, 'capabilities' => null],
        'config:project-init' => ['handler' => ConfigProjectInitHandler::class, 'capabilities' => null],
        'projects:list' => ['handler' => ProjectListHandler::class, 'capabilities' => null],
        'projects:workflow' => ['handler' => ProjectsWorkflowHandler::class, 'capabilities' => null],
        'projects:labels' => ['handler' => ProjectsLabelsHandler::class, 'capabilities' => null],
        'filters:list' => ['handler' => FilterListHandler::class, 'capabilities' => null],
        'filters:show' => ['handler' => FilterShowHandler::class, 'capabilities' => null],
        'items:list' => ['handler' => ItemListHandler::class, 'capabilities' => null],
        'items:search' => ['handler' => SearchHandler::class, 'capabilities' => null],
        'items:show' => ['handler' => ItemShowHandler::class, 'capabilities' => null],
        'items:download' => ['handler' => ItemDownloadHandler::class, 'capabilities' => null],
        'items:upload' => ['handler' => ItemUploadHandler::class, 'capabilities' => null],
        'items:create' => ['handler' => ItemCreateHandler::class, 'capabilities' => null],
        'items:update' => ['handler' => ItemUpdateHandler::class, 'capabilities' => null],
        'items:transition' => ['handler' => ItemTransitionHandler::class, 'capabilities' => null],
        'items:start' => ['handler' => ItemStartHandler::class, 'capabilities' => null],
        'items:takeover' => ['handler' => ItemTakeoverHandler::class, 'capabilities' => null],
        'branch:rename' => ['handler' => BranchRenameHandler::class, 'capabilities' => null],
        'branches:list' => ['handler' => BranchListHandler::class, 'capabilities' => null],
        'branches:clean' => ['handler' => BranchCleanHandler::class, 'capabilities' => null],
        'commit' => ['handler' => CommitHandler::class, 'capabilities' => null],
        'commit:undo' => ['handler' => CommitUndoHandler::class, 'capabilities' => null],
        'push' => ['handler' => PushHandler::class, 'capabilities' => null],
        'please' => ['handler' => PleaseHandler::class, 'capabilities' => null],
        'flatten' => ['handler' => FlattenHandler::class, 'capabilities' => null],
        'sync' => ['handler' => SyncHandler::class, 'capabilities' => null],
        'switch' => ['handler' => BranchSwitchHandler::class, 'capabilities' => null],
        'cache:clear' => ['handler' => CacheClearHandler::class, 'capabilities' => null],
        'status' => ['handler' => StatusHandler::class, 'capabilities' => null],
        'submit' => ['handler' => SubmitHandler::class, 'capabilities' => null],
        'pr:comment' => ['handler' => PrCommentHandler::class, 'capabilities' => null],
        'pr:comments' => ['handler' => PrCommentsHandler::class, 'capabilities' => null],
        'confluence:push' => ['handler' => ConfluencePushHandler::class, 'capabilities' => null],
        'confluence:show' => ['handler' => ConfluenceShowHandler::class, 'capabilities' => null],
        'confluence:page-labels' => ['handler' => null, 'capabilities' => [ConfluenceAware::class]],
        'completion' => ['handler' => null, 'capabilities' => []],
        'docs:generate' => ['handler' => null, 'capabilities' => []],
        'docs:check' => ['handler' => null, 'capabilities' => []],
        'help' => ['handler' => null, 'capabilities' => []],
        'update' => ['handler' => UpdateHandler::class, 'capabilities' => null],
        'release' => ['handler' => ReleaseHandler::class, 'capabilities' => null],
        'deploy' => ['handler' => DeployHandler::class, 'capabilities' => null],
    ];

    public static function isWhitelisted(string $commandName): bool
    {
        return in_array($commandName, self::WHITELISTED_COMMANDS, true);
    }

    public static function canonicalName(string $commandName): string
    {
        $aliasMap = CommandMap::aliasLookupMap();

        return $aliasMap[$commandName] ?? $commandName;
    }

    /**
     * @return class-string|null
     */
    public static function handlerClassFor(string $commandName): ?string
    {
        $canonical = self::canonicalName($commandName);
        $entry = self::ENTRIES[$canonical] ?? null;

        return $entry['handler'] ?? null;
    }

    public static function capabilitiesFor(string $commandName): CapabilitySet
    {
        $canonical = self::canonicalName($commandName);
        $entry = self::ENTRIES[$canonical] ?? null;

        if ($entry === null) {
            return CapabilitySet::fromList([]);
        }

        if ($entry['capabilities'] !== null) {
            return CapabilitySet::fromList($entry['capabilities']);
        }

        /** @var class-string $handlerClass */
        $handlerClass = $entry['handler'];

        return CapabilityDiscovery::fromClass($handlerClass);
    }

    public static function resolveCapabilities(string $commandName): CapabilitySet
    {
        $handlerClass = self::handlerClassFor($commandName);
        if ($handlerClass !== null) {
            return CapabilityDiscovery::fromClass($handlerClass);
        }

        return self::capabilitiesFor($commandName);
    }

    /**
     * @return array<string, array{handler: class-string|null, capabilities: list<class-string>|null}>
     */
    public static function entries(): array
    {
        return self::ENTRIES;
    }
}
