<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;
use App\Service\FileSystem;
use App\Service\Logger;
use App\Service\MigrationExecutor;
use App\Service\TranslationService;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrationExecutorTest extends TestCase
{
    private MigrationExecutor $executor;
    private Logger&MockObject $logger;
    private FileSystem $fileSystem;
    private TranslationService&MockObject $translator;
    private string $testConfigPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        // Use in-memory filesystem instead of mocking
        $adapter = new InMemoryFilesystemAdapter();
        $flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($flysystem);
        $this->translator = $this->createMock(TranslationService::class);
        $this->executor = new MigrationExecutor($this->logger, $this->fileSystem, $this->translator);

        $this->testConfigPath = '/test/config.yml';
    }

    public function testExecuteMigrationsUpdatesVersion(): void
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
                $config['test_key'] = 'test_value';

                return $config;
            }

            public function down(array $config): array
            {
                unset($config['test_key']);

                return $config;
            }
        };

        $config = ['existing_key' => 'existing_value'];
        $expectedConfig = [
            'existing_key' => 'existing_value',
            'test_key' => 'test_value',
            'migration_version' => '202501160000001',
        ];

        $this->translator->method('trans')
            ->willReturnCallback(function ($key, $params = []) {
                return $key . (empty($params) ? '' : ' ' . json_encode($params));
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('text');

        $result = $this->executor->executeMigrations([$migration], $config, $this->testConfigPath);

        $this->assertSame('test_value', $result['test_key']);
        $this->assertSame('202501160000001', $result['migration_version']);

        // Verify file was written to in-memory filesystem
        $this->assertTrue($this->fileSystem->fileExists($this->testConfigPath));
        $writtenConfig = $this->fileSystem->parseFile($this->testConfigPath);
        $this->assertSame($expectedConfig, $writtenConfig);
    }

    public function testExecuteMigrationsHandlesPrerequisiteFailure(): void
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
                return true;
            }

            public function up(array $config): array
            {
                throw new \RuntimeException('Migration failed');
            }

            public function down(array $config): array
            {
                return $config;
            }
        };

        $config = ['existing_key' => 'existing_value'];

        $this->translator->method('trans')
            ->willReturnCallback(function ($key, $params = []) {
                return $key . (empty($params) ? '' : ' ' . json_encode($params));
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Prerequisite migration');

        $this->executor->executeMigrations([$migration], $config, $this->testConfigPath);
    }

    public function testExecuteMigrationsHandlesNonPrerequisiteFailure(): void
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
                throw new \RuntimeException('Migration failed');
            }

            public function down(array $config): array
            {
                return $config;
            }
        };

        $config = ['existing_key' => 'existing_value'];

        $this->translator->method('trans')
            ->willReturnCallback(function ($key, $params = []) {
                return $key . (empty($params) ? '' : ' ' . json_encode($params));
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Non-prerequisite failures should not throw, just log
        $result = $this->executor->executeMigrations([$migration], $config, $this->testConfigPath);

        $this->assertSame($config, $result);
    }

    public function testExecuteMigrationsExecutesInOrder(): void
    {
        $callOrder = [];
        $callOrderRef = &$callOrder;

        $migration1 = new class ($this->logger, $this->translator, $callOrderRef) extends AbstractMigration {
            private array $callOrderRef;

            public function __construct(Logger $logger, TranslationService $translator, array &$callOrderRef)
            {
                parent::__construct($logger, $translator);
                $this->callOrderRef = &$callOrderRef;
            }

            public function getId(): string
            {
                return '202501160000001';
            }

            public function getDescription(): string
            {
                return 'First migration';
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
                $this->callOrderRef[] = 1;
                $config['first'] = true;

                return $config;
            }

            public function down(array $config): array
            {
                return $config;
            }
        };

        $migration2 = new class ($this->logger, $this->translator, $callOrderRef) extends AbstractMigration {
            private array $callOrderRef;

            public function __construct(Logger $logger, TranslationService $translator, array &$callOrderRef)
            {
                parent::__construct($logger, $translator);
                $this->callOrderRef = &$callOrderRef;
            }

            public function getId(): string
            {
                return '202501160000002';
            }

            public function getDescription(): string
            {
                return 'Second migration';
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
                $this->callOrderRef[] = 2;
                $config['second'] = true;

                return $config;
            }

            public function down(array $config): array
            {
                return $config;
            }
        };

        $config = [];

        $this->translator->method('trans')
            ->willReturnCallback(function ($key, $params = []) {
                return $key . (empty($params) ? '' : ' ' . json_encode($params));
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('text');

        $result = $this->executor->executeMigrations([$migration1, $migration2], $config, $this->testConfigPath);

        $this->assertTrue($result['first']);
        $this->assertTrue($result['second']);
        $this->assertSame([1, 2], $callOrder);
        $this->assertSame('202501160000002', $result['migration_version']);
    }

    public function testExecuteMigrationsWithEmptyArray(): void
    {
        $config = ['existing_key' => 'existing_value'];

        $result = $this->executor->executeMigrations([], $config, $this->testConfigPath);

        $this->assertSame($config, $result);
    }

    public function testExecuteMigrationsWithNonAbstractMigration(): void
    {
        $migration = $this->createMock(\App\Migrations\MigrationInterface::class);
        $migration->method('getId')->willReturn('202501160000001');
        $migration->method('getDescription')->willReturn('Test migration');
        $migration->method('getScope')->willReturn(\App\Migrations\MigrationScope::GLOBAL);
        $migration->method('isPrerequisite')->willReturn(false);
        $migration->method('up')->willReturnCallback(function (array $config) {
            $config['test_key'] = 'test_value';

            return $config;
        });

        $config = ['existing_key' => 'existing_value'];
        $expectedConfig = [
            'existing_key' => 'existing_value',
            'test_key' => 'test_value',
            'migration_version' => '202501160000001',
        ];

        $this->translator->method('trans')
            ->willReturnCallback(function ($key, $params = []) {
                return $key . (empty($params) ? '' : ' ' . json_encode($params));
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('text');

        $result = $this->executor->executeMigrations([$migration], $config, $this->testConfigPath);

        $this->assertSame('test_value', $result['test_key']);
        $this->assertSame('202501160000001', $result['migration_version']);

        // Verify file was written to in-memory filesystem
        $this->assertTrue($this->fileSystem->fileExists($this->testConfigPath));
        $writtenConfig = $this->fileSystem->parseFile($this->testConfigPath);
        $this->assertSame($expectedConfig, $writtenConfig);
    }
}
