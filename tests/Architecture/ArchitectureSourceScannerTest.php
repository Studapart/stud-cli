<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ArchitectureSourceScannerTest extends TestCase
{
    public function testUsesWorkflowOutputDetectsImportAndProperty(): void
    {
        self::assertTrue(ArchitectureSourceScanner::usesWorkflowOutput(
            "use App\\Service\\WorkflowOutput;\nclass Foo { public function __construct(private WorkflowOutput \$logger) {} }"
        ));
        self::assertTrue(ArchitectureSourceScanner::usesWorkflowOutput(
            'class Foo { public function __construct(private ?WorkflowOutput $logger = null) {} }'
        ));
        self::assertFalse(ArchitectureSourceScanner::usesWorkflowOutput(
            'class Foo { public function buildWarningResponse(): void {} }'
        ));
        self::assertTrue(ArchitectureSourceScanner::usesWorkflowOutput(
            'new \\App\\Service\\WorkflowOutput($prompt);'
        ));
    }

    public function testDirectConsoleOutputCallMatchesIoOnly(): void
    {
        self::assertTrue(ArchitectureSourceScanner::containsDirectConsoleOutputCall(
            '$io->writeln("hello");'
        ));
        self::assertFalse(ArchitectureSourceScanner::containsDirectConsoleOutputCall(
            '$this->buildWarningResponse("key", []);'
        ));
        self::assertFalse(ArchitectureSourceScanner::containsDirectConsoleOutputCall(
            '$this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, $message);'
        ));
    }
}
