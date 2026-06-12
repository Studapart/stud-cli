<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

/**
 * Presentation-layer classes excluded from handler/service architecture guards.
 *
 * @see CONVENTIONS.md "Command Output Conventions"
 */
final class ArchitecturePresentationExceptions
{
    /**
     * @var list<string> paths relative to project root
     */
    public const ALLOWED_SERVICE_FILES = [
        'src/Service/AgentModeSchemaGenerator.php',
        'src/Service/CommandOutputBuffer.php',
        'src/Service/CommandReferenceGenerator.php',
        'src/Service/HelpService.php',
        'src/Service/Logger.php',
        'src/Service/MarkdownToAdfConverter.php',
        'src/Service/MessageRenderer.php',
        'src/Service/ResponderHelper.php',
        'src/Service/TranslationService.php',
    ];

    /**
     * Handlers still recording via WorkflowOutput pending responder migration (SCI-138).
     *
     * @var list<string>
     */
    public const LEGACY_WORKFLOW_OUTPUT_HANDLERS = [
        'src/Handler/BranchCleanHandler.php',
        'src/Handler/BranchRenameHandler.php',
        'src/Handler/CacheClearHandler.php',
        'src/Handler/ConfigProjectInitPromptCollector.php',
        'src/Handler/DeployHandler.php',
        'src/Handler/InitHandler.php',
        'src/Handler/ItemStartHandler.php',
        'src/Handler/ItemTakeoverHandler.php',
        'src/Handler/ItemTransitionHandler.php',
        'src/Handler/ReleaseHandler.php',
        'src/Handler/StatusHandler.php',
        'src/Handler/SubmitHandler.php',
        'src/Handler/UpdateHandler.php',
    ];

    /**
     * Domain services still recording via WorkflowOutput pending layering cleanup.
     *
     * @var list<string>
     */
    public const LEGACY_WORKFLOW_OUTPUT_SERVICES = [
        'src/Service/BranchCleanupExecutor.php',
        'src/Service/ConfigValidator.php',
        'src/Service/GitSetupService.php',
        'src/Service/InitProjectConfigFollowUpService.php',
        'src/Service/MigrationExecutor.php',
        'src/Service/MigrationRegistry.php',
        'src/Service/PortableUpdateService.php',
        'src/Service/SubmitLabelResolver.php',
        'src/Service/UpdateFileService.php',
    ];
}
