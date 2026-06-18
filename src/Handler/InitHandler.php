<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Response\WorkflowResponse;
use App\Service\FileSystem;
use App\Service\GlobalMigrationIdResolver;
use App\Service\Prompt\PromptInterface;
use Symfony\Component\Yaml\Yaml;

class InitHandler
{
    private WorkflowEntryRecorder $recorder;

    private readonly GlobalMigrationIdResolver $globalMigrationIdResolver;

    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly string $configPath,
        private readonly PromptInterface $prompt,
        private readonly InitPromptCollector $promptCollector,
        ?GlobalMigrationIdResolver $globalMigrationIdResolver = null,
    ) {
        $this->globalMigrationIdResolver = $globalMigrationIdResolver ?? new GlobalMigrationIdResolver($fileSystem);
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     */
    public function handle(array $rawAgentInput = [], bool $isAgent = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $existingConfig = $this->loadExistingConfig();

        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.wizard.title'));
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.wizard.description', ['path' => $this->configPath]));

        $config = $this->promptCollector->buildGlobalConfig($existingConfig, $rawAgentInput, $isAgent, $this->recorder);
        $this->applyMigrationVersion($config, $existingConfig);
        $this->saveConfig($config);
        $this->promptForCompletion();

        return $this->recorder->toResponse(0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadExistingConfig(): array
    {
        return $this->fileSystem->fileExists($this->configPath)
            ? $this->fileSystem->parseFile($this->configPath)
            : [];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $existingConfig
     */
    protected function applyMigrationVersion(array &$config, array $existingConfig): void
    {
        if (isset($existingConfig['migration_version'])) {
            // Tested via integration test (InitHandlerMigrationVersionIntegrationTest)
            $config['migration_version'] = $existingConfig['migration_version'];

            return;
        }

        $latestMigrationId = $this->globalMigrationIdResolver->resolveLatestId();
        if ($latestMigrationId !== null) {
            $config['migration_version'] = $latestMigrationId;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function saveConfig(array $config): void
    {
        $configDir = $this->fileSystem->dirname($this->configPath);
        if (! $this->fileSystem->isDir($configDir)) {
            try {
                $this->fileSystem->mkdir($configDir, 0700, true);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Failed to create config directory: {$configDir}", 0, $e);
            }
        }

        $filteredConfig = array_filter($config, [$this, 'filterEmptyStrings']);
        $this->fileSystem->backupFileIfExists($this->configPath);
        $this->fileSystem->filePutContents($this->configPath, Yaml::dump($filteredConfig));
        $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.success'));
    }

    /**
     * Gets the latest migration ID from the list of migrations.
     *
     * @param array<\App\Migrations\MigrationInterface> $migrations
     * @return string|null The latest migration ID, or null if no migrations exist
     */
    protected function getLatestMigrationId(array $migrations): ?string
    {
        if (empty($migrations)) {
            return null;
        }

        $latestId = null;
        foreach ($migrations as $migration) {
            $migrationId = $migration->getId();
            if ($latestId === null || strcmp($migrationId, $latestId) > 0) {
                $latestId = $migrationId;
            }
        }

        return $latestId;
    }

    protected function promptForCompletion(): void
    {
        $shell = $this->detectShell();
        if ($shell === null) {
            return;
        }

        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.completion.title'));

        $choice = $this->prompt->choice(
            MessageRef::key('config.init.completion.prompt', ['shell' => $shell]),
            [
                'Yes',
                'No',
            ],
            'No'
        );

        if ($choice === 'Yes') {
            $command = $shell === 'bash'
                ? 'echo \'eval "$(stud completion bash)"\' >> ~/.bashrc'
                : 'echo \'eval "$(stud completion zsh)"\' >> ~/.zshrc';

            $shellrc = $shell === 'bash' ? 'bashrc' : 'zshrc';

            $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.completion.success_message'));
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, '  <info>' . $command . '</info>');
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.completion.reload_instruction', ['shellrc' => $shellrc]));
        } else {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('config.init.completion.skipped'));
        }
    }

    protected function detectShell(): ?string
    {
        $shellEnv = getenv('SHELL');
        if ($shellEnv === false) {
            return null;
        }

        $shellName = basename($shellEnv);

        return match (strtolower($shellName)) {
            'bash' => 'bash',
            'zsh' => 'zsh',
            default => null,
        };
    }

    /**
     * Filters out empty strings from config array while preserving null values.
     *
     * @param mixed $value The value to check
     * @return bool True if the value should be kept (not an empty string), false otherwise
     */
    private function filterEmptyStrings($value): bool
    {
        return $value !== '';
    }
}
