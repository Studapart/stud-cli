<?php

namespace App\Tests\Handler;

use App\Handler\ReleaseHandler;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandlerTest extends CommandTestCase
{
    use ProphecyTrait;

    private FileSystem $fileSystem;
    private FlysystemFilesystem $flysystem;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory filesystem
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);

        // ReleaseHandlerTest checks output text, so use real TranslationService
        // This is acceptable since ReleaseHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);
    }

    public function testHandle(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testHandleWithPublishOption(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(\App\Service\Logger::VERBOSITY_NORMAL, 'Would you like to publish the release branch to remote?', false)->shouldNotBeCalled();
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, true, null);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testHandleWithoutPublishOptionAndUserConfirms(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);
        $this->allowReleaseLoggerOutput($logger, true);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(true);
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testHandleWithoutPublishOptionAndUserDeclines(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testUpdateChangelog(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.0';
        $releaseBranch = 'release/v' . $version;
        $currentDate = date('Y-m-d');

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';
        $initialContent = "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Added new feature X.\n- Fixed bug Y.\n\n## [1.1.0] - 2025-10-01\n\n- Initial release.\n";

        $this->flysystem->write($changelogPath, $initialContent);
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        // Verify CHANGELOG.md was updated correctly
        $updatedContent = $this->flysystem->read($changelogPath);
        $expectedHeader = "## [Unreleased]\n\n## [{$version}] - {$currentDate}";
        $this->assertStringContainsString($expectedHeader, $updatedContent);
        $this->assertStringContainsString('- Added new feature X.', $updatedContent);
        $this->assertStringContainsString('- Fixed bug Y.', $updatedContent);
        $this->assertStringContainsString('## [1.1.0] - 2025-10-01', $updatedContent);
        $this->assertStringNotContainsString('## [Unreleased]\n\n### Added', $updatedContent);
    }

    public function testUpdateComposerVersionWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.3';
        $composerJsonPath = '/nonexistent/composer.json';

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read composer.json');

        $this->callPrivateMethod($handler, 'updateComposerVersion', [$version]);
    }

    public function testUpdateComposerVersionWithInvalidJson(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.3';
        $composerJsonPath = '/composer.json';
        $this->flysystem->write($composerJsonPath, 'invalid json');

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format');

        $this->callPrivateMethod($handler, 'updateComposerVersion', [$version]);
    }

    public function testUpdateChangelogWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);

        $version = '1.2.3';
        $changelogPath = '/nonexistent/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read CHANGELOG.md');

        $this->callPrivateMethod($handler, 'updateChangelog', [$version]);
    }

    public function testUpdateReadmeReplacesMarkedPharFilename(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $readmePath = '/README.md';
        $content = "<!-- release-version:start -->Download stud-3.4.1.phar and stud-3.4.1.phar again.<!-- release-version:end -->\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['3.5.0']);

        $updated = $this->flysystem->read($readmePath);
        $this->assertSame("<!-- release-version:start -->Download stud-3.5.0.phar and stud-3.5.0.phar again.<!-- release-version:end -->\n", $updated);
    }

    public function testUpdateReadmeReplacesMarkedDownloadUrl(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $readmePath = '/README.md';
        $content = "<!-- release-version:start -->https://github.com/Studapart/stud-cli/releases/download/v2.1.0/stud-2.1.0.phar<!-- release-version:end -->\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['3.0.0']);

        $updated = $this->flysystem->read($readmePath);
        $this->assertSame("<!-- release-version:start -->https://github.com/Studapart/stud-cli/releases/download/v3.0.0/stud-3.0.0.phar<!-- release-version:end -->\n", $updated);
    }

    public function testUpdateReadmeLeavesUnmarkedVersionReferencesUnchanged(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $readmePath = '/README.md';
        $content = "https://github.com/Studapart/stud-cli/releases/download/v2.1.0/stud-2.1.0.phar\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['3.0.0']);

        $this->assertSame($content, $this->flysystem->read($readmePath));
    }

    public function testUpdateReadmeLeavesNonMatchingContentUnchanged(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $readmePath = '/README.md';
        $content = "Some text without version patterns. stud-1.2.phar invalid.\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['2.0.0']);

        $updated = $this->flysystem->read($readmePath);
        $this->assertSame("Some text without version patterns. stud-1.2.phar invalid.\n", $updated);
    }

    public function testUpdateReadmeHandlesReadmeWithoutVersionReferences(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $readmePath = '/README.md';
        $content = "# Title\n\nNo version here.\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['1.0.0']);

        $this->assertSame($content, $this->flysystem->read($readmePath));
    }

    public function testUpdateReadmeWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $readmePath = '/nonexistent/README.md';

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read README.md');

        $this->callPrivateMethod($handler, 'updateReadme', ['1.0.0']);
    }

    public function testCalculateNextVersionPatch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, 'composer.json', 'CHANGELOG.md');

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'patch']);
        $this->assertSame('2.6.3', $result);
    }

    public function testCalculateNextVersionMinor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'minor']);
        $this->assertSame('2.7.0', $result);
    }

    public function testCalculateNextVersionMajor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'major']);
        $this->assertSame('3.0.0', $result);
    }

    public function testCalculateNextVersionInvalidFormat(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid version format: invalid. Expected format: X.Y.Z");

        $this->callPrivateMethod($handler, 'calculateNextVersion', ['invalid', 'patch']);
    }

    public function testCalculateNextVersionInvalidBumpType(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid bump type: invalid. Must be 'major', 'minor', or 'patch'");

        $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'invalid']);
    }

    public function testHandleWithPatchBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);
        $this->allowReleaseLoggerOutput($logger, false);

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), null, false, 'patch');

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testHandleWithMinorBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.7.0';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), null, false, 'minor');

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testHandleWithMajorBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);

        $currentVersion = '2.6.2';
        $targetVersion = '3.0.0';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), null, false, 'major');

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testHandleWithDefaultPatchBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);
        $logger = $this->prophesize(\App\Service\Logger::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $logger->addSection(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->addText(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();
        $logger->confirm(Argument::any(), false)->willReturn(false);
        $logger->addSuccess(\App\Service\Logger::VERBOSITY_NORMAL, Argument::any())->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        // No version and no bump type - should default to patch
        $handler->handle($io->reveal(), null, false, null);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testGetCurrentVersion(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $currentVersion = '2.6.2';

        // Create file in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');
        $result = $this->callPrivateMethod($handler, 'getCurrentVersion');

        $this->assertSame($currentVersion, $result);
    }

    public function testGetCurrentVersionWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);

        // Use a non-existent file path to trigger file_get_contents failure
        $composerJsonPath = '/nonexistent/composer_' . uniqid() . '.json';

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read composer.json');

        $this->callPrivateMethod($handler, 'getCurrentVersion');
    }

    public function testGetCurrentVersionWithMissingVersion(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);

        // Create file in in-memory filesystem without version
        $composerJsonPath = '/composer.json';
        $this->flysystem->write($composerJsonPath, json_encode(['name' => 'test/package']));

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format or missing version field');

        $this->callPrivateMethod($handler, 'getCurrentVersion');
    }

    public function testConstructor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $logger = $this->prophesize(\App\Service\Logger::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $logger->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);

        $this->assertInstanceOf(ReleaseHandler::class, $handler);
    }

    private function allowReleaseLoggerOutput(object $logger, bool $confirm): void
    {
        $logger->addSection(Argument::any(), Argument::any());
        $logger->addText(Argument::any(), Argument::any());
        $logger->addSuccess(Argument::any(), Argument::any());
        $logger->confirm(Argument::any(), false)->willReturn($confirm);
    }
}
