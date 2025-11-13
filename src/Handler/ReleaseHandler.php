<?php

namespace App\Handler;

use App\Service\GitRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly string $composerJsonPath = 'composer.json',
        private readonly string $changelogPath = 'CHANGELOG.md',
    ) {
    }

    public function handle(SymfonyStyle $io, string $version, bool $publish = false): void
    {
        $io->section('Starting release process for version ' . $version);

        $this->gitRepository->fetch();
        $io->text('Fetched latest changes from origin.');

        $releaseBranch = 'release/v' . $version;
        $this->gitRepository->createBranch($releaseBranch, 'origin/develop');
        $io->text('Created release branch: ' . $releaseBranch);

        $this->updateComposerVersion($version);
        $io->text('Updated version in composer.json to ' . $version);

        $this->gitRepository->run('composer update --lock');
        $io->text('Updated composer.lock');

        $this->gitRepository->run('composer dump-config');
        $io->text('Dumped config to config/app.php');

        $this->updateChangelog($version);
        $io->text('Updated CHANGELOG.md with version ' . $version);

        $this->gitRepository->stageAllChanges();
        $io->text('Staged changes.');

        $this->gitRepository->commit('chore(Version): Bump version to ' . $version);
        $io->text('Committed version bump.');

        if ($publish) {
            $this->gitRepository->pushToOrigin($releaseBranch);
            $io->text('Release branch published to remote.');
        } else {
            if ($io->confirm('Would you like to publish the release branch to remote?', false)) {
                $this->gitRepository->pushToOrigin($releaseBranch);
                $io->text('Release branch published to remote.');
            }
        }

        $io->success('Release ' . $version . ' is ready to be deployed.');
    }

    protected function updateComposerVersion(string $version): void
    {
        $composerJson = json_decode(file_get_contents($this->composerJsonPath), true);
        $composerJson['version'] = $version;
        file_put_contents($this->composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function updateChangelog(string $version): void
    {
        $content = file_get_contents($this->changelogPath);
        
        $currentDate = date('Y-m-d');
        $newVersionHeader = "## [{$version}] - {$currentDate}";
        $unreleasedHeader = '## [Unreleased]';
        
        // Replace ## [Unreleased] with ## [Unreleased] followed by the new version header
        $replacement = $unreleasedHeader . "\n\n" . $newVersionHeader;
        $updatedContent = str_replace($unreleasedHeader, $replacement, $content);
        
        file_put_contents($this->changelogPath, $updatedContent);
    }
}
