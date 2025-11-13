<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly TranslationService $translator,
        private readonly string $composerJsonPath = 'composer.json',
        private readonly string $changelogPath = 'CHANGELOG.md',
    ) {
    }

    public function handle(SymfonyStyle $io, string $version, bool $publish = false): void
    {
        $io->section($this->translator->trans('release.section', ['version' => $version]));

        $this->gitRepository->fetch();
        $io->text($this->translator->trans('release.fetched'));

        $releaseBranch = 'release/v' . $version;
        $this->gitRepository->createBranch($releaseBranch, 'origin/develop');
        $io->text($this->translator->trans('release.created_branch', ['branch' => $releaseBranch]));

        $this->updateComposerVersion($version);
        $io->text($this->translator->trans('release.updated_composer', ['version' => $version]));

        $this->gitRepository->run('composer update --lock');
        $io->text($this->translator->trans('release.updated_lock'));

        $this->gitRepository->run('composer dump-config');
        $io->text($this->translator->trans('release.dumped_config'));

        $this->updateChangelog($version);
        $io->text($this->translator->trans('release.updated_changelog', ['version' => $version]));

        $this->gitRepository->stageAllChanges();
        $io->text($this->translator->trans('release.staged'));

        $this->gitRepository->commit('chore(Version): Bump version to ' . $version);
        $io->text($this->translator->trans('release.committed'));

        if ($publish) {
            $this->gitRepository->pushToOrigin($releaseBranch);
            $io->text($this->translator->trans('release.published'));
        } else {
            if ($io->confirm($this->translator->trans('release.confirm_publish'), false)) {
                $this->gitRepository->pushToOrigin($releaseBranch);
                $io->text($this->translator->trans('release.published'));
            }
        }

        $io->success($this->translator->trans('release.success', ['version' => $version]));
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
