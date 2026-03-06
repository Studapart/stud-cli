<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
use App\Response\ItemCreateResponse;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateHandler
{
    private const STANDARD_REQUIRED_FIELDS = ['project', 'issuetype', 'summary', 'description'];

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(
        SymfonyStyle $io,
        bool $interactive,
        ?string $project,
        ?string $type,
        ?string $summary,
        ?string $descriptionOption
    ): ItemCreateResponse {
        $project = $this->resolveProject($io, $interactive, $project);
        if ($project === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_no_project'));
        }

        $type = $type !== null && $type !== '' ? trim($type) : 'Story';

        $summary = $this->resolveSummary($io, $interactive, $summary);
        if ($summary === null || $summary === '') {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_no_summary'));
        }

        $description = $this->getDescription($descriptionOption);

        $issueTypeId = $this->resolveIssueTypeId($project, $type);
        if ($issueTypeId === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_createmeta', ['error' => "Issue type \"{$type}\" not found for project \"{$project}\""]));
        }

        $requiredFieldIds = $this->getRequiredFieldIds($project, $issueTypeId);
        if ($requiredFieldIds === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $extraRequired = array_diff($requiredFieldIds, self::STANDARD_REQUIRED_FIELDS);
        if ($extraRequired !== []) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_extra_required'));
        }

        $fields = [
            'project' => ['key' => $project],
            'issuetype' => ['id' => $issueTypeId],
            'summary' => $summary,
        ];
        if ($description !== null && $description !== '') {
            $fields['description'] = $this->jiraService->plainTextToDescriptionAdf($description);
        }

        try {
            $result = $this->jiraService->createIssue($fields);

            return ItemCreateResponse::success($result['key'], $result['self']);
        } catch (ApiException $e) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_create', ['error' => $e->getMessage()]));
        } catch (\Throwable $e) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_create', ['error' => $e->getMessage()]));
        }
    }

    protected function resolveProject(SymfonyStyle $io, bool $interactive, ?string $project): ?string
    {
        if ($project !== null && trim($project) !== '') {
            return trim($project);
        }
        $config = $this->gitRepository->readProjectConfig();
        /** @var array<string, mixed> $config */
        $defaultProject = isset($config['JIRA_DEFAULT_PROJECT']) ? (string) $config['JIRA_DEFAULT_PROJECT'] : null;
        if ($defaultProject !== null && trim($defaultProject) !== '') {
            return trim($defaultProject);
        }
        if (! $interactive) {
            return null;
        }

        return $io->ask($this->translator->trans('item.create.prompt_project'));
    }

    protected function resolveSummary(SymfonyStyle $io, bool $interactive, ?string $summary): ?string
    {
        if ($summary !== null && trim($summary) !== '') {
            return trim($summary);
        }
        if (! $interactive) {
            return null;
        }

        return $io->ask($this->translator->trans('item.create.prompt_summary'));
    }

    /**
     * Description precedence: STDIN first, then option. Same as pr:comment.
     */
    protected function getDescription(?string $descriptionOption): ?string
    {
        $stdinContent = $this->readStdin();
        if ($stdinContent !== '') {
            return $stdinContent;
        }
        if ($descriptionOption !== null && trim($descriptionOption) !== '') {
            return trim($descriptionOption);
        }

        return null;
    }

    /**
     * Reads content from STDIN if available (non-blocking). Returns empty string if TTY or no content.
     * Same behaviour as PrCommentHandler::readStdin(); STDIN paths are not unit-testable without process execution.
     */
    protected function readStdin(): string
    {
        // @codeCoverageIgnoreStart - TTY check not simulable in unit tests
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            return '';
        }
        if (is_resource(STDIN)) {
            $metaData = stream_get_meta_data(STDIN);
            $wasBlocking = $metaData['blocked'];
            stream_set_blocking(STDIN, false);
            $content = stream_get_contents(STDIN);
            stream_set_blocking(STDIN, $wasBlocking);

            if ($content !== false) {
                return trim($content);
            }
        }
        if (! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
            $content = @file_get_contents('php://stdin');

            return $content !== false ? trim($content) : '';
        }
        // @codeCoverageIgnoreEnd

        return '';
    }

    /**
     * @return string|null Issue type id or null if not found / API error
     */
    protected function resolveIssueTypeId(string $projectKey, string $typeName): ?string
    {
        try {
            $issueTypes = $this->jiraService->getCreateMetaIssueTypes($projectKey);
        } catch (\Throwable) {
            return null;
        }
        $typeNameLower = strtolower($typeName);
        foreach ($issueTypes as $it) {
            if (strtolower($it['name']) === $typeNameLower) {
                return $it['id'];
            }
        }

        return null;
    }

    /**
     * @return list<string>|null Required field IDs, or null on API error
     */
    protected function getRequiredFieldIds(string $projectKey, string $issueTypeId): ?array
    {
        try {
            $fields = $this->jiraService->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return null;
        }
        $required = [];
        foreach ($fields as $fieldId => $meta) {
            if ($meta['required']) {
                $required[] = $fieldId;
            }
        }

        return $required;
    }
}
