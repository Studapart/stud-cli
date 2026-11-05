<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\MessageRef;
use App\Service\TranslationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for confluence:push task (castor layer).
 *
 * Verifies agent mode file handling: non-readable file path yields JSON error and exit 1.
 * CLI update with --file uses the same content-loading branch as create (see castor.php);
 * handler tests cover update flow with content.
 */
#[Group('integration')]
class ConfluencePushTaskIntegrationTest extends TestCase
{
    public function testAgentModeWithFileNotReadableOutputsErrorAndExits(): void
    {
        $path = '/nonexistent/' . bin2hex(random_bytes(8)) . '.md';
        $input = (string) json_encode([
            'file' => $path,
            'page' => '123',
            'url' => 'https://example.com',
        ]);
        $home = sys_get_temp_dir() . '/stud-confluence-push-' . bin2hex(random_bytes(4));
        $configDir = $home . '/.config/stud';
        mkdir($configDir, 0700, true);
        file_put_contents($configDir . '/config.yml', <<<'YAML'
LANGUAGE: en
JIRA_URL: https://example.atlassian.net
JIRA_EMAIL: user@example.com
JIRA_API_TOKEN: token
migration_version: '999999999999999'
YAML);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'confluence:push',
                '--agent',
            ],
            __DIR__ . '/../..',
            [
                'APP_ENV' => 'test',
                'CASTOR_DISABLE_VERSION_CHECK' => '1',
                'HOME' => $home,
            ],
            null,
            15
        );
        $proc->setInput($input);

        try {
            $proc->run();
            $stdout = $proc->getOutput();
            $stderr = $proc->getErrorOutput();
            $fullOutput = $stdout . $stderr;
            $decoded = null;
            foreach (array_reverse(explode("\n", trim($fullOutput))) as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] === '{') {
                    $decoded = json_decode($line, true);

                    break;
                }
            }

            self::assertSame(1, $proc->getExitCode(), 'Expected exit code 1 when file is not readable. Output: ' . $fullOutput);
            self::assertIsArray($decoded, 'Expected JSON line in output. Output: ' . $fullOutput);
            self::assertFalse($decoded['success'] ?? true);
            self::assertArrayHasKey('error', $decoded);
            $translator = new TranslationService('en', dirname(__DIR__, 2) . '/src/resources/translations');
            $expected = $translator->renderForAgentText(MessageRef::key('confluence.push.error_file_not_readable', ['%path%' => $path]));
            self::assertSame($expected, $decoded['error']);
        } finally {
            @unlink($configDir . '/config.yml');
            @rmdir($configDir);
            @rmdir($home . '/.config');
            @rmdir($home);
        }
    }
}
