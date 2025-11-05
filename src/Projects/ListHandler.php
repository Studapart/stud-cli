<?php

namespace App\Projects;

use App\DTO\Project;
use App\Jira\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListHandler
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $io->section('Fetching Jira Projects');
        try {
            $projects = $this->jiraService->getProjects();
        } catch (\Exception $e) {
            $io->error('Failed to fetch projects: ' . $e->getMessage());
            return;
        }

        if (empty($projects)) {
            $io->note('No projects found.');
            return;
        }

        $table = array_map(fn (Project $project) => [$project->key, $project->name], $projects);
        $io->table(['Key', 'Name'], $table);
    }
}
