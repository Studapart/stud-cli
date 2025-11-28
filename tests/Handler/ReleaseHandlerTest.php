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
        $handler->handle($io->reveal(), $version, false);

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
        $handler->handle($io->reveal(), $version, true);

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
        $handler->handle($io->reveal(), $version, false);

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
        $handler->handle($io->reveal(), $version, false);

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
        $handler->handle($io->reveal(), $version, false);

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
}