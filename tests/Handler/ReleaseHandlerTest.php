<?php

namespace App\Tests\Handler;

use App\Handler\ReleaseHandler;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandlerTest extends CommandTestCase
{
    use ProphecyTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // ReleaseHandlerTest checks output text, so use real TranslationService
        // This is acceptable since ReleaseHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);
    }

    public function testHandle(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $io->section('Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $io->success('Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        // Create a dummy composer.json
        $composerJsonPath = __DIR__ . '/composer.json';
        file_put_contents($composerJsonPath, json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testHandleWithPublishOption(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $io->section('Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $io->text('Release branch published to remote.')->shouldBeCalled();
        $io->confirm('Would you like to publish the release branch to remote?', false)->shouldNotBeCalled();
        $io->success('Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        // Create a dummy composer.json
        $composerJsonPath = __DIR__ . '/composer.json';
        file_put_contents($composerJsonPath, json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, true, null);

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testHandleWithoutPublishOptionAndUserConfirms(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $io->section('Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(true);
        $gitRepository->pushToOrigin($releaseBranch)->shouldBeCalled();
        $io->text('Release branch published to remote.')->shouldBeCalled();
        $io->success('Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        // Create a dummy composer.json
        $composerJsonPath = __DIR__ . '/composer.json';
        file_put_contents($composerJsonPath, json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testHandleWithoutPublishOptionAndUserDeclines(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $io->section('Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $io->success('Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        // Create a dummy composer.json
        $composerJsonPath = __DIR__ . '/composer.json';
        file_put_contents($composerJsonPath, json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($version, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testUpdateChangelog(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.0';
        $releaseBranch = 'release/v' . $version;
        $currentDate = date('Y-m-d');

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        $initialContent = "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Added new feature X.\n- Fixed bug Y.\n\n## [1.1.0] - 2025-10-01\n\n- Initial release.\n";
        file_put_contents($changelogPath, $initialContent);

        // Create a dummy composer.json
        $composerJsonPath = sys_get_temp_dir() . '/composer.json';
        file_put_contents($composerJsonPath, json_encode(['version' => '1.0.0']));

        $io->section('Starting release process for version ' . $version)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $version)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $version)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $gitRepository->pushToOrigin($releaseBranch)->shouldNotBeCalled();
        $io->success('Release ' . $version . ' is ready to be deployed.')->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), $version, false, null);

        // Verify CHANGELOG.md was updated correctly
        $updatedContent = file_get_contents($changelogPath);
        $expectedHeader = "## [Unreleased]\n\n## [{$version}] - {$currentDate}";
        $this->assertStringContainsString($expectedHeader, $updatedContent);
        $this->assertStringContainsString('- Added new feature X.', $updatedContent);
        $this->assertStringContainsString('- Fixed bug Y.', $updatedContent);
        $this->assertStringContainsString('## [1.1.0] - 2025-10-01', $updatedContent);
        $this->assertStringNotContainsString('## [Unreleased]\n\n### Added', $updatedContent);

        // Clean up
        unlink($changelogPath);
        unlink($composerJsonPath);
    }

    public function testUpdateComposerVersionWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $composerJsonPath = '/nonexistent/composer.json';

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read composer.json');

        $this->callPrivateMethod($handler, 'updateComposerVersion', [$version]);
    }

    public function testUpdateComposerVersionWithInvalidJson(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, 'invalid json');

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format');

        try {
            $this->callPrivateMethod($handler, 'updateComposerVersion', [$version]);
        } finally {
            unlink($composerJsonPath);
        }
    }

    public function testUpdateChangelogWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $changelogPath = '/nonexistent/CHANGELOG.md';

        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read CHANGELOG.md');

        try {
            $this->callPrivateMethod($handler, 'updateChangelog', [$version]);
        } finally {
            unlink($composerJsonPath);
        }
    }

    public function testCalculateNextVersionPatch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'patch']);
        $this->assertSame('2.6.3', $result);
    }

    public function testCalculateNextVersionMinor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'minor']);
        $this->assertSame('2.7.0', $result);
    }

    public function testCalculateNextVersionMajor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService);

        $result = $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'major']);
        $this->assertSame('3.0.0', $result);
    }

    public function testCalculateNextVersionInvalidFormat(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid version format: invalid. Expected format: X.Y.Z");

        $this->callPrivateMethod($handler, 'calculateNextVersion', ['invalid', 'patch']);
    }

    public function testCalculateNextVersionInvalidBumpType(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid bump type: invalid. Must be 'major', 'minor', or 'patch'");

        $this->callPrivateMethod($handler, 'calculateNextVersion', ['2.6.2', 'invalid']);
    }

    public function testHandleWithPatchBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create a dummy composer.json with current version
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['version' => $currentVersion]));

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        $io->section('Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $io->success('Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), null, false, 'patch');

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testHandleWithMinorBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.7.0';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create a dummy composer.json with current version
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['version' => $currentVersion]));

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        $io->section('Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $io->success('Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), null, false, 'minor');

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testHandleWithMajorBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $currentVersion = '2.6.2';
        $targetVersion = '3.0.0';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create a dummy composer.json with current version
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['version' => $currentVersion]));

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        $io->section('Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $io->success('Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        $handler->handle($io->reveal(), null, false, 'major');

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testHandleWithDefaultPatchBump(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $currentVersion = '2.6.2';
        $targetVersion = '2.6.3';
        $releaseBranch = 'release/v' . $targetVersion;

        // Create a dummy composer.json with current version
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['version' => $currentVersion]));

        // Create a temporary CHANGELOG.md file
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';
        file_put_contents($changelogPath, "# Changelog\n\n## [Unreleased]\n\n");

        $io->section('Starting release process for version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->fetch()->shouldBeCalled();
        $io->text('Fetched latest changes from origin.')->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $io->text('Created release branch: ' . $releaseBranch)->shouldBeCalled();
        $io->text('Updated version in composer.json to ' . $targetVersion)->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $io->text('Updated composer.lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $io->text('Dumped config to config/app.php')->shouldBeCalled();
        $io->text('Updated CHANGELOG.md with version ' . $targetVersion)->shouldBeCalled();
        $gitRepository->stageAllChanges()->shouldBeCalled();
        $io->text('Staged changes.')->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $targetVersion)->shouldBeCalled();
        $io->text('Committed version bump.')->shouldBeCalled();
        $io->confirm(Argument::any(), false)->willReturn(false);
        $io->success('Release ' . $targetVersion . ' is ready to be deployed.')->shouldBeCalled();

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);
        // No version and no bump type - should default to patch
        $handler->handle($io->reveal(), null, false, null);

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $this->assertSame($targetVersion, $composerJson['version']);

        // Clean up
        unlink($composerJsonPath);
        unlink($changelogPath);
    }

    public function testGetCurrentVersion(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $currentVersion = '2.6.2';

        // Create a dummy composer.json with current version
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['version' => $currentVersion]));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, 'CHANGELOG.md');
        $result = $this->callPrivateMethod($handler, 'getCurrentVersion');

        $this->assertSame($currentVersion, $result);

        // Clean up
        unlink($composerJsonPath);
    }

    public function testGetCurrentVersionWithFileReadError(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);

        // Use a non-existent file path to trigger file_get_contents failure
        $composerJsonPath = '/nonexistent/composer_' . uniqid() . '.json';

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read composer.json');

        $this->callPrivateMethod($handler, 'getCurrentVersion');
    }

    public function testGetCurrentVersionWithMissingVersion(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);

        // Create a dummy composer.json without version
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        file_put_contents($composerJsonPath, json_encode(['name' => 'test/package']));

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, 'CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format or missing version field');

        try {
            $this->callPrivateMethod($handler, 'getCurrentVersion');
        } finally {
            unlink($composerJsonPath);
        }
    }

    public function testConstructor(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $composerJsonPath = sys_get_temp_dir() . '/composer_' . uniqid() . '.json';
        $changelogPath = sys_get_temp_dir() . '/CHANGELOG_' . uniqid() . '.md';

        $handler = new ReleaseHandler($gitRepository->reveal(), $this->translationService, $composerJsonPath, $changelogPath);

        $this->assertInstanceOf(ReleaseHandler::class, $handler);
    }
}
