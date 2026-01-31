<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbstractMigrationTest extends TestCase
{
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
    }

    public function testExecuteCallsUpMethod(): void
    {
        $migration = new class ($this->logger, $this->translator) extends AbstractMigration {
            public function getId(): string
            {
                return '202501160000001';
            }

            public function getDescription(): string
            {
                return 'Test migration';
            }

            public function getScope(): MigrationScope
            {
                return MigrationScope::GLOBAL;
            }

            public function isPrerequisite(): bool
            {
                return false;
            }

            public function up(array $config): array
            {
                $config['migrated'] = true;

                return $config;
            }

            public function down(array $config): array
            {
                unset($config['migrated']);

                return $config;
            }
        };

        $config = ['original' => 'value'];
        $result = $migration->execute($config);

        $this->assertTrue($result['migrated']);
        $this->assertSame('value', $result['original']);
    }

    public function testExecuteCallsBeforeUpHook(): void
    {
        $beforeUpCalled = false;
        $beforeUpCalledRef = &$beforeUpCalled;

        $migration = new class ($this->logger, $this->translator, $beforeUpCalledRef) extends AbstractMigration {
            private bool $beforeUpCalledRef;

            public function __construct(Logger $logger, TranslationService $translator, bool &$beforeUpCalledRef)
            {
                parent::__construct($logger, $translator);
                $this->beforeUpCalledRef = &$beforeUpCalledRef;
            }

            public function getId(): string
            {
                return '202501160000001';
            }

            public function getDescription(): string
            {
                return 'Test migration';
            }

            public function getScope(): MigrationScope
            {
                return MigrationScope::GLOBAL;
            }

            public function isPrerequisite(): bool
            {
                return false;
            }

            protected function beforeUp(array $config): void
            {
                $this->beforeUpCalledRef = true;
            }

            public function up(array $config): array
            {
                return $config;
            }

            public function down(array $config): array
            {
                return $config;
            }
        };

        $migration->execute(['test' => 'value']);

        $this->assertTrue($beforeUpCalled);
    }

    public function testExecuteCallsAfterUpHook(): void
    {
        $afterUpCalled = false;
        $afterUpCalledRef = &$afterUpCalled;

        $migration = new class ($this->logger, $this->translator, $afterUpCalledRef) extends AbstractMigration {
            private bool $afterUpCalledRef;

            public function __construct(Logger $logger, TranslationService $translator, bool &$afterUpCalledRef)
            {
                parent::__construct($logger, $translator);
                $this->afterUpCalledRef = &$afterUpCalledRef;
            }

            public function getId(): string
            {
                return '202501160000001';
            }

            public function getDescription(): string
            {
                return 'Test migration';
            }

            public function getScope(): MigrationScope
            {
                return MigrationScope::GLOBAL;
            }

            public function isPrerequisite(): bool
            {
                return false;
            }

            public function up(array $config): array
            {
                return $config;
            }

            protected function afterUp(array $config): void
            {
                $this->afterUpCalledRef = true;
            }

            public function down(array $config): array
            {
                return $config;
            }
        };

        $migration->execute(['test' => 'value']);

        $this->assertTrue($afterUpCalled);
    }
}
