<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Project;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $io->section($this->translator->trans('project.list.section'));

        try {
            $projects = $this->jiraService->getProjects();
        } catch (\Exception $e) {
            $io->error($this->translator->trans('project.list.error_fetch', ['error' => $e->getMessage()]));

            return;
        }

        if (empty($projects)) {
            $io->note($this->translator->trans('project.list.no_projects'));

            return;
        }

        $table = array_map(fn (Project $project) => [$project->key, $project->name], $projects);
        $io->table([
            $this->translator->trans('table.key'),
            $this->translator->trans('table.name'),
        ], $table);
    }
}
