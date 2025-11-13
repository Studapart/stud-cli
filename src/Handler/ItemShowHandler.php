<?php

namespace App\Handler;

use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\TableSeparator;

class ItemShowHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly array $jiraConfig,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, string $key): void
    {
        $key = strtoupper($key);
        $io->section($this->translator->trans('item.show.section', ['key' => $key]));
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('item.show.fetching', ['key' => $key])}</>");
        }
        try {
            $issue = $this->jiraService->getIssue($key, true);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.show.error_not_found', ['key' => $key]));
            return;
        }

        $io->definitionList(
            [$this->translator->trans('item.show.label_key') => $issue->key],
            [$this->translator->trans('item.show.label_title') => $issue->title],
            [$this->translator->trans('item.show.label_status') => $issue->status],
            [$this->translator->trans('item.show.label_assignee') => $issue->assignee],
            [$this->translator->trans('item.show.label_type') => $issue->issueType],
            [$this->translator->trans('item.show.label_labels') => !empty($issue->labels) ? implode(', ', $issue->labels) : $this->translator->trans('item.show.label_none')],
            new TableSeparator(), // separator
            [$this->translator->trans('item.show.label_description') => $issue->description],
            new TableSeparator(), // separator
            [$this->translator->trans('item.show.label_link') => $this->jiraConfig['JIRA_URL'] . '/browse/' . $issue->key]
        );
    }
}