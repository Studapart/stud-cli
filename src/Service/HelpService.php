<?php

namespace App\Service;

use App\Service\TranslationService;

class HelpService
{
    private const README_PATH = __DIR__ . '/../../README.md';
    
    // Map of command names to their README section patterns
    private const COMMAND_PATTERNS = [
        'config:init' => 'stud config:init',
        'completion' => 'stud completion',
        'projects:list' => 'stud projects:list',
        'items:list' => 'stud items:list',
        'items:search' => 'stud items:search',
        'items:show' => 'stud items:show',
        'items:start' => 'stud items:start',
        'commit' => 'stud commit',
        'please' => 'stud please',
        'status' => 'stud status',
        'submit' => 'stud submit',
        'update' => 'stud update',
        'release' => 'stud release',
        'deploy' => 'stud deploy',
    ];

    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    /**
     * Get help text for a command from README.md
     */
    public function getCommandHelp(string $commandName): ?string
    {
        if (!isset(self::COMMAND_PATTERNS[$commandName])) {
            return null;
        }

        $readmeContent = @file_get_contents(self::README_PATH);
        if ($readmeContent === false) {
            return null;
        }

        $pattern = self::COMMAND_PATTERNS[$commandName];
        $lines = explode("\n", $readmeContent);
        
        $helpLines = [];
        $inSection = false;
        $foundCommand = false;
        
        foreach ($lines as $line) {
            // Look for the command section (starts with -   **`stud ...`)
            if (!$foundCommand && preg_match('/^-\s+\*\*`' . preg_quote($pattern, '/') . '/', $line)) {
                $foundCommand = true;
                $inSection = true;
                // Extract the command line itself
                $helpLines[] = $line;
                continue;
            }
            
            if ($inSection) {
                // Stop at next command (starts with -   **`stud) or section header (####)
                if (preg_match('/^#### |^-\s+\*\*`stud /', $line)) {
                    $currentIndent = strlen($line) - strlen(ltrim($line));
                    // If it's a new command at same or less indent, stop
                    if (str_contains($line, 'stud ') && $currentIndent <= 4) {
                        break;
                    }
                    // If it's a section header, stop
                    if (preg_match('/^#### /', $line)) {
                        break;
                    }
                }
                
                // Collect help text lines (skip empty lines at start)
                if (!empty($helpLines) || trim($line) !== '') {
                    $helpLines[] = $line;
                }
            }
        }
        
        if (empty($helpLines)) {
            return null;
        }
        
        // Clean up and format the help text
        $helpText = implode("\n", $helpLines);
        // Remove markdown formatting but keep structure
        $helpText = preg_replace('/\*\*`([^`]+)`\*\*/', '$1', $helpText); // Remove bold code markers
        $helpText = preg_replace('/`([^`]+)`/', '$1', $helpText); // Remove code markers
        $helpText = preg_replace('/\*\*([^*]+)\*\*/', '$1', $helpText); // Remove bold markers
        $helpText = preg_replace('/^-\s+/m', '', $helpText); // Remove list markers
        $helpText = preg_replace('/^    /m', '', $helpText); // Remove indentation
        $helpText = trim($helpText);
        
        return $helpText ?: null;
    }

    /**
     * Display help for a command
     */
    public function displayCommandHelp(\Symfony\Component\Console\Style\SymfonyStyle $io, string $commandName): void
    {
        $helpText = $this->getCommandHelp($commandName);
        
        if ($helpText === null) {
            // Fallback to translation if README extraction fails
            $translationKey = "help.command_{$commandName}";
            $helpText = $this->translator->trans($translationKey);
            if ($helpText === $translationKey) {
                $helpText = $this->translator->trans('help.command_not_found', ['command' => $commandName]);
            }
        }
        
        $io->section($this->translator->trans('help.command_help_title', ['command' => $commandName]));
        $io->writeln($helpText);
        $io->newLine();
        $io->note($this->translator->trans('help.see_readme_note'));
    }
}

