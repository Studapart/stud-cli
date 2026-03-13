<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\AgentModeException;

/**
 * Helper for agent mode (--agent): read JSON input from stdin or file, write JSON output.
 * Optional I/O is for testing; production uses stdin/stdout when null.
 *
 * @param null|callable(): string $stdinReader When set (e.g. in tests), used instead of php://stdin when no input file is given.
 * @param null|callable(string): (string|false) $fileReader When set (e.g. in tests), used instead of file_get_contents for reading input file.
 * @param null|callable(): bool $isStdinTty When set (e.g. in tests), used instead of posix_isatty(STDIN) to decide if stdin is interactive.
 */
class AgentModeHelper
{
    public function __construct(
        private readonly ?AgentModeIoInterface $io = null,
        private readonly ?\Closure $stdinReader = null,
        private readonly ?\Closure $fileReader = null,
        private readonly ?\Closure $isStdinTty = null
    ) {
    }

    /**
     * Read and decode JSON input. Reads from $inputFile when non-null and readable, otherwise from stdin (or injected input stream).
     *
     * @return array<string, mixed>
     *
     * @throws AgentModeException when JSON is invalid or file cannot be read
     */
    public function readAgentInput(?string $inputFile): array
    {
        $raw = $this->readRawInput($inputFile);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AgentModeException('Invalid JSON: ' . json_last_error_msg());
        }
        if (! is_array($decoded) || (count($decoded) > 0 && array_is_list($decoded))) {
            throw new AgentModeException('JSON input must be an object');
        }

        return $decoded;
    }

    /**
     * Build success payload for agent output.
     *
     * @param array<string, mixed> $data
     * @return array{success: true, data: array<string, mixed>}
     */
    public function buildSuccessPayload(array $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    /**
     * Build error payload for agent output.
     *
     * @return array{success: false, error: string}
     */
    public function buildErrorPayload(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    /**
     * Write a single JSON object to output. When I/O is injected, writes to it; otherwise returns the JSON line for the caller to output and exit.
     *
     * @param array{success: bool, error?: string, data?: mixed} $payload Must contain "success"; on failure "error", on success "data".
     *
     * @return string|null The JSON line to echo and then exit when io is null; null when written to injected io.
     */
    public function writeAgentOutput(array $payload): ?string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $line = $json . "\n";
        if ($this->io !== null) {
            $this->io->write($line);

            return null;
        }

        return $line;
    }

    /**
     * Exit code for a payload: 0 on success, 1 on failure.
     *
     * @param array{success: bool, error?: string, data?: mixed} $payload
     */
    public function exitCodeForPayload(array $payload): int
    {
        return $payload['success'] ? 0 : 1;
    }

    /**
     * Read raw string from file or stdin (or injected input stream).
     */
    protected function readRawInput(?string $inputFile): string
    {
        if ($inputFile !== null && $inputFile !== '') {
            return $this->readFromFile($inputFile);
        }

        return $this->readFromStdin();
    }

    protected function readFromFile(string $inputFile): string
    {
        if (! is_readable($inputFile)) {
            throw new AgentModeException('Cannot read input file: ' . $inputFile);
        }
        $reader = $this->fileReader ?? fn (string $path): string|false => file_get_contents($path);
        $content = $reader($inputFile);
        if ($content === false) {
            throw new AgentModeException('Failed to read input file: ' . $inputFile);
        }

        return $content;
    }

    protected function readFromStdin(): string
    {
        if ($this->io !== null) {
            return $this->io->getContents();
        }
        if ($this->stdinReader !== null) {
            $stdin = ($this->stdinReader)();
            if ($stdin === false || $stdin === '') {
                throw new AgentModeException('Failed to read stdin');
            }

            return $stdin;
        }
        $ttyCheck = $this->isStdinTty ?? fn (): bool => function_exists('posix_isatty') && @posix_isatty(STDIN);
        if ($ttyCheck()) {
            return '{}';
        }
        // Blocking read from real stdin — only reachable in production (piped input)
        // @codeCoverageIgnoreStart
        $stdin = file_get_contents('php://stdin');
        if ($stdin === false || $stdin === '') {
            throw new AgentModeException('Failed to read stdin');
        }

        return $stdin;
        // @codeCoverageIgnoreEnd
    }
}
