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
     * Handlers must not record via WorkflowOutput (use WorkflowRecorder + WorkflowResponse).
     *
     * @var list<string>
     */
    public const LEGACY_WORKFLOW_OUTPUT_HANDLERS = [];

    /**
     * Domain services still recording via WorkflowOutput pending layering cleanup.
     *
     * @var list<string>
     */
    public const LEGACY_WORKFLOW_OUTPUT_SERVICES = [
        'src/Service/ConfigRemediationService.php',
        'src/Service/GitSetupService.php',
    ];
}
