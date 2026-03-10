<?php

/**
 * Test fixture functions for AgentModeSchemaGenerator edge cases.
 *
 * These fake task functions exercise the schema generator's output paths
 * that the real castor.php tasks (all annotated) cannot reach.
 */

declare(strict_types=1);

use App\Attribute\AgentOutput;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

#[AsTask(name: 'test:no-output-attr', description: 'Fixture: task without AgentOutput')]
function _test_fixture_no_output_attr(
    #[AsOption(name: 'agent')]
    bool $agent = false,
): void {
}

#[AsTask(name: 'test:empty-output-attr', description: 'Fixture: task with empty AgentOutput')]
#[AgentOutput]
function _test_fixture_empty_output_attr(
    #[AsOption(name: 'agent')]
    bool $agent = false,
): void {
}
