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
        $logger = $this->prophesize(\App\Service\Logger::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

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

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Release branch published to remote.')->shouldBeCalled();
        $logger->confirm(\App\Service\Logger::VERBOSITY_NORMAL, 'Would you like to publish the release branch to remote?', false)->shouldNotBeCalled();
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

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

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(true);
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Release branch published to remote.')->shouldBeCalled();
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

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

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

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

        $version = '1.2.0';
        $releaseBranch = 'release/v' . $version;
        $currentDate = date('Y-m-d');

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';
        $initialContent = "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Added new feature X.\n- Fixed bug Y.\n\n## [1.1.0] - 2025-10-01\n\n- Initial release.\n";

        $this->flysystem->write($changelogPath, $initialContent);
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

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

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

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

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

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

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

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

        $logger->section(\App\Service\Logger::VERBOSITY_NORMAL, 'Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Dumped config to config/app.php')->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $logger->text(\App\Service\Logger::VERBOSITY_NORMAL, 'Committed version bump.')->shouldBeCalled();
        $logger->confirm(Argument::type('string'), false)->willReturn(false);
        $logger->success(\App\Service\Logger::VERBOSITY_NORMAL, 'Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

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
}
