<?php

namespace App\Handler;

use App\Service\GitRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
    ) {
    }

    public function handle(SymfonyStyle $io, string $version): void
    {
        $io->title('Starting release process for version ' . $version);

        $this->gitRepository->fetch();
        $io->comment('Fetched latest changes from origin.');

        $releaseBranch = 'release/v' . $version;
        $this->gitRepository->createBranch($releaseBranch, 'origin/develop');
        $io->comment('Created release branch: ' . $releaseBranch);

        $this->updateComposerVersion($version);
        $io->comment('Updated version in composer.json to ' . $version);

        $this->gitRepository->run('composer update --lock');
        $io->comment('Updated composer.lock');

        $this->gitRepository->run('composer dump-config');
        $io->comment('Dumped config to config/app.php');

        $this->gitRepository->add(['composer.json', 'composer.lock', 'config/app.php']);
        $this->gitRepository->commit('chore(Version): Bump version to ' . $version);
        $io->comment('Committed version bump.');

        $io->success('Release ' . $version . ' is ready to be deployed.');
    }

    private function updateComposerVersion(string $version): void
    {
        $composerJson = json_decode(file_get_contents('composer.json'), true);
        $composerJson['version'] = $version;
        file_put_contents('composer.json', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
