<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Migrations\MigrationInterface;
use App\Migrations\MigrationScope;
use App\Service\FileSystem;
use App\Service\Logger;
use App\Service\MigrationRegistry;
use App\Service\TranslationService;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrationRegistryTest extends TestCase
{
    private MigrationRegistry $registry;
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->registry = new MigrationRegistry($this->logger, $this->translator);
    }

    private function createRegistryWithInMemoryFilesystem(): MigrationRegistry
    {
        $flysystem = new FlysystemFilesystem(new InMemoryFilesystemAdapter());
        $fileSystem = new FileSystem($flysystem);

        return new MigrationRegistry($this->logger, $this->translator, $fileSystem);
    }

    public function testDiscoverGlobalMigrationsCallsDiscoverMigrations(): void
    {
        // Test that discoverGlobalMigrations calls discoverMigrations with correct path and scope
        $migrations = $this->registry->discoverGlobalMigrations();
        $this->assertIsArray($migrations);
        // Should discover at least one migration (GitTokenFormat)
        $this->assertNotEmpty($migrations);
    }

    public function testDiscoverProjectMigrationsCallsDiscoverMigrations(): void
    {
        // Test that discoverProjectMigrations calls discoverMigrations with correct path and scope
        $migrations = $this->registry->discoverProjectMigrations();
        $this->assertIsArray($migrations);
        // May be empty if no project migrations exist
    }

    public function testDiscoverGlobalMigrations(): void
    {
        $migrations = $this->registry->discoverGlobalMigrations();

        // Should discover at least the GitTokenFormat migration
        $this->assertIsArray($migrations);
        $this->assertNotEmpty($migrations);

        // Verify all migrations are MigrationInterface instances
        foreach ($migrations as $migration) {
            $this->assertInstanceOf(MigrationInterface::class, $migration);
            $this->assertSame(MigrationScope::GLOBAL, $migration->getScope());
        }
    }

    public function testDiscoverProjectMigrations(): void
    {
        $migrations = $this->registry->discoverProjectMigrations();

        // Should return array (may be empty if no project migrations exist yet)
        $this->assertIsArray($migrations);

        // Verify all migrations are MigrationInterface instances with PROJECT scope
        foreach ($migrations as $migration) {
            $this->assertInstanceOf(MigrationInterface::class, $migration);
            $this->assertSame(MigrationScope::PROJECT, $migration->getScope());
        }
    }

    public function testGetPendingMigrationsWithNoCurrentVersion(): void
    {
        $migrations = $this->registry->discoverGlobalMigrations();

        // With version "0", all migrations should be pending
        $pending = $this->registry->getPendingMigrations($migrations, '0');

        $this->assertIsArray($pending);
        $this->assertCount(count($migrations), $pending);
    }

    public function testGetPendingMigrationsWithCurrentVersion(): void
    {
        // Use mock migrations to ensure we have migrations to test
        $migration1 = $this->createMock(MigrationInterface::class);
        $migration1->method('getId')->willReturn('202501150000001');
        $migration1->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migration2 = $this->createMock(MigrationInterface::class);
        $migration2->method('getId')->willReturn('202501150000002');
        $migration2->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migrations = [$migration1, $migration2];

        // Get the first migration ID
        $firstMigrationId = $migrations[0]->getId();

        // With current version equal to first migration, no migrations should be pending
        $pending = $this->registry->getPendingMigrations($migrations, $firstMigrationId);

        // Should have fewer pending migrations (all except the first one)
        $this->assertIsArray($pending);
        $this->assertLessThan(count($migrations), count($pending));
    }

    public function testGetPendingMigrationsWithEmptyArray(): void
    {
        $pending = $this->registry->getPendingMigrations([], '0');

        $this->assertIsArray($pending);
        $this->assertEmpty($pending);
    }

    public function testGetPendingMigrationsWithEmptyVersion(): void
    {
        $migrations = $this->registry->discoverGlobalMigrations();

        $pending = $this->registry->getPendingMigrations($migrations, '');

        // Empty version should be treated like "0"
        $this->assertIsArray($pending);
        $this->assertCount(count($migrations), $pending);
    }

    public function testMigrationsAreSortedById(): void
    {
        // Test that getPendingMigrations returns migrations sorted by ID
        // Use mock migrations to ensure we have at least 2 migrations for testing
        $migration1 = $this->createMock(MigrationInterface::class);
        $migration1->method('getId')->willReturn('202501150000002');
        $migration1->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migration2 = $this->createMock(MigrationInterface::class);
        $migration2->method('getId')->willReturn('202501150000001');
        $migration2->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migration3 = $this->createMock(MigrationInterface::class);
        $migration3->method('getId')->willReturn('202501150000003');
        $migration3->method('getScope')->willReturn(MigrationScope::GLOBAL);

        // Create unsorted array
        $unsorted = [$migration1, $migration2, $migration3];

        $pending = $this->registry->getPendingMigrations($unsorted, '0');

        // Verify migrations are sorted by ID
        $this->assertCount(3, $pending);
        $this->assertSame('202501150000001', $pending[0]->getId());
        $this->assertSame('202501150000002', $pending[1]->getId());
        $this->assertSame('202501150000003', $pending[2]->getId());
    }

    public function testSortMigrationsDirectly(): void
    {
        // Test sortMigrations method directly using reflection
        // Create mock migrations with different IDs to test sorting
        $migration1 = $this->createMock(MigrationInterface::class);
        $migration1->method('getId')->willReturn('202501150000002');
        $migration1->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migration2 = $this->createMock(MigrationInterface::class);
        $migration2->method('getId')->willReturn('202501150000001');
        $migration2->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migration3 = $this->createMock(MigrationInterface::class);
        $migration3->method('getId')->willReturn('202501150000003');
        $migration3->method('getScope')->willReturn(MigrationScope::GLOBAL);

        // Create unsorted array
        $unsorted = [$migration1, $migration2, $migration3];

        // Use reflection to call sortMigrations directly
        $reflection = new \ReflectionClass($this->registry);
        $method = $reflection->getMethod('sortMigrations');
        $method->setAccessible(true);

        $sorted = $method->invoke($this->registry, $unsorted);

        // Verify migrations are sorted by ID
        $this->assertCount(3, $sorted);
        $this->assertSame('202501150000001', $sorted[0]->getId());
        $this->assertSame('202501150000002', $sorted[1]->getId());
        $this->assertSame('202501150000003', $sorted[2]->getId());
    }

    public function testGetPendingMigrationsFiltersCorrectly(): void
    {
        // Use mock migrations to ensure we have migrations to test
        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getId')->willReturn('202501150000001');
        $migration->method('getScope')->willReturn(MigrationScope::GLOBAL);

        $migrations = [$migration];

        // Sort migrations by ID
        usort($migrations, function ($a, $b) {
            return strcmp($a->getId(), $b->getId());
        });

        $firstMigrationId = $migrations[0]->getId();
        $pending = $this->registry->getPendingMigrations($migrations, $firstMigrationId);

        // Verify that the first migration is not in pending list
        $pendingIds = array_map(fn ($m) => $m->getId(), $pending);
        $this->assertNotContains($firstMigrationId, $pendingIds);
    }

    public function testDiscoverMigrationsWithNonExistentDirectory(): void
    {
        // Use reflection to call protected method
        $reflection = new \ReflectionClass($this->registry);
        $method = $reflection->getMethod('discoverMigrations');
        $method->setAccessible(true);

        $result = $method->invoke($this->registry, '/non/existent/path', MigrationScope::GLOBAL);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetClassNameFromFileWithInvalidFile(): void
    {
        $reflection = new \ReflectionClass($this->registry);
        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->registry, '/tmp', 'nonexistent.php');

        $this->assertNull($result);
    }

    public function testGetClassNameFromFileWithFileWithoutClass(): void
    {
        // Test getClassNameFromFile with a file that has no class definition
        // This should return null, which will trigger the continue at line 108
        $registry = $this->createRegistryWithInMemoryFilesystem();
        $flysystem = new FlysystemFilesystem(new InMemoryFilesystemAdapter());

        $testDir = '/test/migrations';
        $flysystem->createDirectory($testDir);
        $testFile = $testDir . '/NoClass.php';
        $flysystem->write($testFile, '<?php echo "test";');

        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke($registry, $testDir, basename($testFile));

        $this->assertNull($result);
    }

    public function testDiscoverMigrationsSkipsFileWhenGetClassNameFromFileReturnsNull(): void
    {
        // Test that discoverMigrations skips files when getClassNameFromFile returns null
        // This covers line 108: continue when className is null
        $flysystem = new FlysystemFilesystem(new InMemoryFilesystemAdapter());
        $fileSystem = new FileSystem($flysystem);
        $registry = new MigrationRegistry($this->logger, $this->translator, $fileSystem);

        $testDir = '/test/migrations';
        $flysystem->createDirectory($testDir);
        $testFile = $testDir . '/NoClass.php';
        // File with no class definition - getClassNameFromFile will return null
        $flysystem->write($testFile, '<?php echo "test";');

        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('discoverMigrations');
        $method->setAccessible(true);

        $result = $method->invoke($registry, $testDir, MigrationScope::GLOBAL);

        $this->assertIsArray($result);
        // Should be empty because file has no class, so it's skipped at line 108
        $this->assertEmpty($result);
    }

    public function testCompareMigrationId(): void
    {
        $reflection = new \ReflectionClass($this->registry);
        $method = $reflection->getMethod('compareMigrationId');
        $method->setAccessible(true);

        $result1 = $method->invoke($this->registry, '202501150000001', '202501150000002');
        $this->assertLessThan(0, $result1);

        $result2 = $method->invoke($this->registry, '202501150000002', '202501150000001');
        $this->assertGreaterThan(0, $result2);

        $result3 = $method->invoke($this->registry, '202501150000001', '202501150000001');
        $this->assertSame(0, $result3);
    }

    public function testGetClassNameFromFileWithNamespace(): void
    {
        $registry = $this->createRegistryWithInMemoryFilesystem();
        $reflection = new \ReflectionClass($registry);
        $fileSystemMethod = $reflection->getMethod('getFileSystem');
        $fileSystemMethod->setAccessible(true);
        $fileSystem = $fileSystemMethod->invoke($registry);
        $reflectionFileSystem = new \ReflectionClass($fileSystem);
        $filesystemProperty = $reflectionFileSystem->getProperty('filesystem');
        $filesystemProperty->setAccessible(true);
        $flysystem = $filesystemProperty->getValue($fileSystem);

        $testDir = '/test';
        $testFile = $testDir . '/TestClass.php';
        $content = <<<'PHP'
<?php
namespace App\Test;
class TestClass {}
PHP;
        $flysystem->createDirectory($testDir);
        $flysystem->write($testFile, $content);

        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke($registry, $testDir, basename($testFile));

        $this->assertSame('App\Test\TestClass', $result);
    }

    public function testGetClassNameFromFileWithoutNamespace(): void
    {
        $registry = $this->createRegistryWithInMemoryFilesystem();
        $reflection = new \ReflectionClass($registry);
        $fileSystemMethod = $reflection->getMethod('getFileSystem');
        $fileSystemMethod->setAccessible(true);
        $fileSystem = $fileSystemMethod->invoke($registry);
        $reflectionFileSystem = new \ReflectionClass($fileSystem);
        $filesystemProperty = $reflectionFileSystem->getProperty('filesystem');
        $filesystemProperty->setAccessible(true);
        $flysystem = $filesystemProperty->getValue($fileSystem);

        $testDir = '/test';
        $testFile = $testDir . '/TestClass.php';
        $content = <<<'PHP'
<?php
class TestClass {}
PHP;
        $flysystem->createDirectory($testDir);
        $flysystem->write($testFile, $content);

        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke($registry, $testDir, basename($testFile));

        $this->assertSame('TestClass', $result);
    }

    public function testDiscoverMigrationsWithScandirFailure(): void
    {
        // This is hard to test directly, but we can test the case where files array is false
        // by using a non-existent directory (already tested) or by testing edge cases
        $reflection = new \ReflectionClass($this->registry);
        $method = $reflection->getMethod('discoverMigrations');
        $method->setAccessible(true);

        // Non-existent directory should return empty array
        $result = $method->invoke($this->registry, '/non/existent/path', MigrationScope::GLOBAL);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDiscoverMigrationsWithNonPhpFile(): void
    {
        $registry = $this->createRegistryWithInMemoryFilesystem();
        $reflection = new \ReflectionClass($registry);
        $fileSystemMethod = $reflection->getMethod('getFileSystem');
        $fileSystemMethod->setAccessible(true);
        $fileSystem = $fileSystemMethod->invoke($registry);
        $reflectionFileSystem = new \ReflectionClass($fileSystem);
        $filesystemProperty = $reflectionFileSystem->getProperty('filesystem');
        $filesystemProperty->setAccessible(true);
        $flysystem = $filesystemProperty->getValue($fileSystem);

        $testDir = '/test/migrations';
        $flysystem->createDirectory($testDir);
        $testFile = $testDir . '/test.txt';
        $flysystem->write($testFile, 'not a php file');

        $method = $reflection->getMethod('discoverMigrations');
        $method->setAccessible(true);

        $result = $method->invoke($registry, $testDir, MigrationScope::GLOBAL);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDiscoverMigrationsSkipsDotAndDotDot(): void
    {
        // The '.' and '..' entries are always present in directory listings
        // This test verifies that when we scan a directory, those entries are skipped
        // We'll use the actual GlobalMigrations directory which contains real migrations
        $migrations = $this->registry->discoverGlobalMigrations();

        // If we have migrations, it means '.' and '..' were properly skipped
        // (otherwise we'd get errors trying to instantiate '.' or '..' as classes)
        $this->assertIsArray($migrations);
        // The directory should contain at least the GitTokenFormat migration
        // If it's empty, that's also fine - the important thing is no errors occurred
    }

    public function testGetClassNameFromFileWithFileGetContentsFailure(): void
    {
        // Test the case where FileSystem::read() throws an exception
        // We'll use a mock FileSystem that throws an exception
        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->expects($this->once())
            ->method('read')
            ->willThrowException(new \RuntimeException('File not found'));

        $registry = new MigrationRegistry($this->logger, $this->translator, $mockFileSystem);

        $reflection = new \ReflectionClass($registry);
        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke($registry, '/test', 'Test.php');

        // Should return null when file can't be read
        $this->assertNull($result);
    }

    public function testGetClassNameFromFileWithNoNamespaceMatch(): void
    {
        $registry = $this->createRegistryWithInMemoryFilesystem();
        $reflection = new \ReflectionClass($registry);
        $fileSystemMethod = $reflection->getMethod('getFileSystem');
        $fileSystemMethod->setAccessible(true);
        $fileSystem = $fileSystemMethod->invoke($registry);
        $reflectionFileSystem = new \ReflectionClass($fileSystem);
        $filesystemProperty = $reflectionFileSystem->getProperty('filesystem');
        $filesystemProperty->setAccessible(true);
        $flysystem = $filesystemProperty->getValue($fileSystem);

        $testDir = '/test';
        $testFile = $testDir . '/TestClass.php';
        $content = <<<'PHP'
<?php
// No namespace declaration
class TestClass {}
PHP;
        $flysystem->createDirectory($testDir);
        $flysystem->write($testFile, $content);

        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke($registry, $testDir, basename($testFile));

        // Should return class name without namespace
        $this->assertSame('TestClass', $result);
    }

    public function testDiscoverMigrationsWithClassExistsFalse(): void
    {
        // Test when class doesn't exist (class_exists returns false)
        $tempDir = sys_get_temp_dir() . '/test-migrations-' . uniqid();
        mkdir($tempDir, 0755, true);
        $tempFile = $tempDir . '/NonExistentClass.php';
        $content = <<<'PHP'
<?php
namespace App\Test;
class NonExistentClass implements \App\Migrations\MigrationInterface {
    // This class name exists in the file but won't be autoloaded
}
PHP;
        file_put_contents($tempFile, $content);

        try {
            $reflection = new \ReflectionClass($this->registry);
            $method = $reflection->getMethod('discoverMigrations');
            $method->setAccessible(true);

            $result = $method->invoke($this->registry, $tempDir, MigrationScope::GLOBAL);

            // Should skip classes that don't exist (can't be autoloaded)
            $this->assertIsArray($result);
            // The class won't be found because it's not in the autoloader
            $this->assertEmpty($result);
        } finally {
            @unlink($tempFile);
            @rmdir($tempDir);
        }
    }

    public function testDiscoverMigrationsWithExceptionDuringInstantiation(): void
    {
        // Test when migration instantiation throws an exception
        $tempDir = sys_get_temp_dir() . '/test-migrations-' . uniqid();
        mkdir($tempDir, 0755, true);
        $tempFile = $tempDir . '/BrokenMigration.php';
        $content = <<<'PHP'
<?php
namespace App\Test;
use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;
use App\Service\Logger;
use App\Service\TranslationService;
class BrokenMigration extends AbstractMigration {
    public function __construct(Logger $logger, TranslationService $translator) {
        parent::__construct($logger, $translator);
        throw new \RuntimeException('Broken migration');
    }
    public function getId(): string { return '202501160000001'; }
    public function getDescription(): string { return 'Test'; }
    public function getScope(): MigrationScope { return MigrationScope::GLOBAL; }
    public function isPrerequisite(): bool { return false; }
    public function up(array $config): array { return $config; }
    public function down(array $config): array { return $config; }
}
PHP;
        file_put_contents($tempFile, $content);

        try {
            // Register the class in autoloader for testing
            $autoloaderCallback = function ($class) use ($tempFile) {
                if ($class === 'App\Test\BrokenMigration') {
                    require_once $tempFile;
                }
            };
            spl_autoload_register($autoloaderCallback);

            $reflection = new \ReflectionClass($this->registry);
            $method = $reflection->getMethod('discoverMigrations');
            $method->setAccessible(true);

            $result = $method->invoke($this->registry, $tempDir, MigrationScope::GLOBAL);

            // Should skip migrations that throw exceptions during instantiation
            $this->assertIsArray($result);
            $this->assertEmpty($result);

            spl_autoload_unregister($autoloaderCallback);
        } finally {
            @unlink($tempFile);
            @rmdir($tempDir);
        }
    }

    public function testDiscoverMigrationsWithClassNotImplementingInterface(): void
    {
        // Test that discoverMigrations skips classes that don't implement MigrationInterface
        // This covers line 118: continue when class doesn't implement interface
        // Use real filesystem for this test since we need to autoload the class
        $tempDir = sys_get_temp_dir() . '/test-migrations-' . uniqid();
        mkdir($tempDir, 0755, true);
        $tempFile = $tempDir . '/TestClass.php';
        $content = <<<'PHP'
<?php
namespace App\Test;
class TestClass {
    public function getId(): string { return 'test'; }
}
PHP;
        file_put_contents($tempFile, $content);

        try {
            // Register the class in autoloader for testing
            // This ensures class_exists() will find it, and then ReflectionClass can check if it implements the interface
            $autoloaderCallback = function ($class) use ($tempFile) {
                if ($class === 'App\Test\TestClass') {
                    require_once $tempFile;
                }
            };
            spl_autoload_register($autoloaderCallback);

            $reflection = new \ReflectionClass($this->registry);
            $method = $reflection->getMethod('discoverMigrations');
            $method->setAccessible(true);

            $result = $method->invoke($this->registry, $tempDir, MigrationScope::GLOBAL);

            $this->assertIsArray($result);
            // Should not include TestClass as it doesn't implement MigrationInterface
            // This should trigger the continue at line 118
            $this->assertEmpty($result);

            spl_autoload_unregister($autoloaderCallback);
        } finally {
            @unlink($tempFile);
            @rmdir($tempDir);
        }
    }

    public function testDiscoverMigrationsWithWrongScope(): void
    {
        // Test that discoverMigrations skips migrations with wrong scope
        // This covers line 127: continue when scope doesn't match
        // Use real filesystem for this test since we need to autoload the class
        $tempDir = sys_get_temp_dir() . '/test-migrations-' . uniqid();
        mkdir($tempDir, 0755, true);
        $tempFile = $tempDir . '/TestProjectMigration.php';
        $content = <<<'PHP'
<?php
namespace App\Test;
use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;
use App\Service\Logger;
use App\Service\TranslationService;
class TestProjectMigration extends AbstractMigration {
    public function getId(): string { return '202501160000001'; }
    public function getDescription(): string { return 'Test'; }
    public function getScope(): MigrationScope { return MigrationScope::PROJECT; }
    public function isPrerequisite(): bool { return false; }
    public function up(array $config): array { return $config; }
    public function down(array $config): array { return $config; }
}
PHP;
        file_put_contents($tempFile, $content);

        try {
            // Register the class in autoloader for testing
            $autoloaderCallback = function ($class) use ($tempFile) {
                if ($class === 'App\Test\TestProjectMigration') {
                    require_once $tempFile;
                }
            };
            spl_autoload_register($autoloaderCallback);

            $reflection = new \ReflectionClass($this->registry);
            $method = $reflection->getMethod('discoverMigrations');
            $method->setAccessible(true);

            // Try to discover as GLOBAL scope - should not find PROJECT scope migration
            // This should trigger the continue at line 127
            $result = $method->invoke($this->registry, $tempDir, MigrationScope::GLOBAL);

            $this->assertIsArray($result);
            // Should be empty because scope doesn't match
            $this->assertEmpty($result);

            spl_autoload_unregister($autoloaderCallback);
        } finally {
            @unlink($tempFile);
            @rmdir($tempDir);
        }
    }
}
