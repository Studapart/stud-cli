<?php

namespace App\Tests\Handler;

use App\Handler\ReleaseHandler;
use App\Response\WorkflowResponse;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;
use App\Tests\CommandTestCase;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

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
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle($version, false, null);

        $this->assertSuccessfulReleaseResponse($response, 11);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testHandleWithPublishOption(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->shouldNotBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle($version, true, null);

        $this->assertSuccessfulReleaseResponse($response, 12);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testHandleWithoutPublishOptionAndUserConfirms(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(true);
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle($version, false, null);

        $this->assertSuccessfulReleaseResponse($response, 12);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testHandleWithoutPublishOptionAndUserDeclines(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();

        // Create files in in-memory filesystem
        $changelogPath = '/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));
        $this->flysystem->write('README.md', "stud-1.0.0.phar\n");

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle($version, false, null);

        $this->assertSuccessfulReleaseResponse($response, 11);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);
    }

    public function testUpdateChangelog(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

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

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle($version, false, null);

        $this->assertSuccessfulReleaseResponse($response, 11);

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
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $composerJsonPath = '/nonexistent/composer.json';

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read composer.json');

        $this->callPrivateMethod($handler, 'updateComposerVersion', [$version]);
    }

    public function testUpdateComposerVersionWithInvalidJson(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $composerJsonPath = '/composer.json';
        $this->flysystem->write($composerJsonPath, 'invalid json');

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format');

        $this->callPrivateMethod($handler, 'updateComposerVersion', [$version]);
    }

    public function testUpdateChangelogWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $version = '1.2.3';
        $changelogPath = '/nonexistent/CHANGELOG.md';
        $composerJsonPath = '/composer.json';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read CHANGELOG.md');

        $this->callPrivateMethod($handler, 'updateChangelog', [$version]);
    }

    public function testUpdateReadmeReplacesMarkedPharFilename(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $readmePath = '/README.md';
        $content = "<!-- release-version:start -->Download stud-3.4.1.phar and stud-3.4.1.phar again.<!-- release-version:end -->\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['3.5.0']);

        $updated = $this->flysystem->read($readmePath);
        $this->assertSame("<!-- release-version:start -->Download stud-3.5.0.phar and stud-3.5.0.phar again.<!-- release-version:end -->\n", $updated);
    }

    public function testUpdateReadmeReplacesMarkedDownloadUrl(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $readmePath = '/README.md';
        $content = "<!-- release-version:start -->https://github.com/Studapart/stud-cli/releases/download/v2.1.0/stud-2.1.0.phar<!-- release-version:end -->\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['3.0.0']);

        $updated = $this->flysystem->read($readmePath);
        $this->assertSame("<!-- release-version:start -->https://github.com/Studapart/stud-cli/releases/download/v3.0.0/stud-3.0.0.phar<!-- release-version:end -->\n", $updated);
    }

    public function testUpdateReadmeLeavesUnmarkedVersionReferencesUnchanged(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $readmePath = '/README.md';
        $content = "https://github.com/Studapart/stud-cli/releases/download/v2.1.0/stud-2.1.0.phar\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['3.0.0']);

        $this->assertSame($content, $this->flysystem->read($readmePath));
    }

    public function testUpdateReadmeLeavesNonMatchingContentUnchanged(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $readmePath = '/README.md';
        $content = "Some text without version patterns. stud-1.2.phar invalid.\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['2.0.0']);

        $updated = $this->flysystem->read($readmePath);
        $this->assertSame("Some text without version patterns. stud-1.2.phar invalid.\n", $updated);
    }

    public function testUpdateReadmeHandlesReadmeWithoutVersionReferences(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $readmePath = '/README.md';
        $content = "# Title\n\nNo version here.\n";
        $this->flysystem->write($readmePath, $content);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);
        $this->callPrivateMethod($handler, 'updateReadme', ['1.0.0']);

        $this->assertSame($content, $this->flysystem->read($readmePath));
    }

    public function testUpdateReadmeWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $readmePath = '/nonexistent/README.md';

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, '/composer.json', '/CHANGELOG.md', $readmePath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read README.md');

        $this->callPrivateMethod($handler, 'updateReadme', ['1.0.0']);
    }

    public function testCalculateNextVersionPatch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, 'composer.json', 'CHANGELOG.md');

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'patch']);
        $this->assertSame('2.6.3', $result);
    }

    public function testCalculateNextVersionMinor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'minor']);
        $this->assertSame('2.7.0', $result);
    }

    public function testCalculateNextVersionMajor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'major']);
        $this->assertSame('3.0.0', $result);
    }

    public function testCalculateNextVersionInvalidFormat(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid version format: invalid. Expected format: X.Y.Z");

        $this->callPrivateMethod($handler, 'calculateNextVersion', ['invalid', 'patch']);
    }

    public function testCalculateNextVersionInvalidBumpType(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid bump type: invalid. Must be 'major', 'minor', or 'patch'");

        $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'invalid']);
    }

    public function testHandleWithPatchBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle(null, false, 'patch');

        $this->assertSuccessfulReleaseResponse($response, 11);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testHandleWithMinorBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.7.0';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle(null, false, 'minor');

        $this->assertSuccessfulReleaseResponse($response, 11);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testHandleWithMajorBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $currentVersion = '2.6.2';
        $targetVersion = '3.0.0';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        $response = $handler->handle(null, false, 'major');

        $this->assertSuccessfulReleaseResponse($response, 11);

        $composerJson = json_decode($this->flysystem->read($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);
    }

    public function testHandleWithDefaultPatchBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $prompt = $this->prophesize(PromptInterface::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create files in in-memory filesystem
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $this->flysystem->write($composerJsonPath, json_encode(['version' => $currentVersion]));
        $this->flysystem->write($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");
        $this->flysystem->write('README.md', "stud-2.6.2.phar\n");

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $prompt->confirm(Argument::any(), false)->willReturn(false);

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);
        // No version and no bump type - should default to patch
        $response = $handler->handle(null, false, null);

        $this->assertSuccessfulReleaseResponse($response, 11);

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

        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');
        $result = $this->callPrivateMethod($handler, 'getCurrentVersion');

        $this->assertSame($currentVersion, $result);
    }

    public function testGetCurrentVersionWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);

        // Use a non-existent file path to trigger file_get_contents failure
        $composerJsonPath = '/nonexistent/composer_' . uniqid() . '.json';

        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

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

        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format or missing version field');

        $this->callPrivateMethod($handler, 'getCurrentVersion');
    }

    public function testConstructor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $composerJsonPath = '/composer.json';
        $changelogPath = '/CHANGELOG.md';

        $prompt = $this->prophesize(PromptInterface::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $prompt->reveal(), $this->fileSystem, $composerJsonPath, $changelogPath);

        $this->assertInstanceOf(ReleaseHandler::class, $handler);
    }

    private function assertSuccessfulReleaseResponse(WorkflowResponse $response, ?int $expectedEntryCount = null): void
    {
        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertSame(0, $response->exitCode);
        $this->assertTrue($response->isSuccess());

        if ($expectedEntryCount !== null) {
            $this->assertCount($expectedEntryCount, $response->entries);
        }
    }
}
