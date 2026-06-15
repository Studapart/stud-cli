<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class WorkflowOutputLegacyAllowListTest extends TestCase
{
    public function testHandlersUsingWorkflowOutputMatchLegacyAllowList(): void
    {
        $found = ArchitectureSourceScanner::filesUsingWorkflowOutput('src/Handler');

        self::assertSame(
            ArchitecturePresentationExceptions::LEGACY_WORKFLOW_OUTPUT_HANDLERS,
            $found,
            'Unexpected WorkflowOutput usage in handlers. '
            . 'Record via WorkflowRecorder and return WorkflowResponse from the handler.'
        );
    }

    public function testServicesUsingWorkflowOutputMatchLegacyAllowList(): void
    {
        $found = ArchitectureSourceScanner::filesUsingWorkflowOutput('src/Service');

        self::assertSame(
            ArchitecturePresentationExceptions::LEGACY_WORKFLOW_OUTPUT_SERVICES,
            $found,
            'Unexpected WorkflowOutput usage in services. '
            . 'Update the allow list only when intentionally recording via WorkflowOutput.'
        );
    }

    public function testResponderMigratedHandlersAreNotOnLegacyAllowList(): void
    {
        $legacy = ArchitecturePresentationExceptions::LEGACY_WORKFLOW_OUTPUT_HANDLERS;

        self::assertNotContains('src/Handler/ItemShowHandler.php', $legacy);
        self::assertNotContains('src/Handler/CommitHandler.php', $legacy);
        self::assertNotContains('src/Handler/ItemCreateHandler.php', $legacy);
    }
}
