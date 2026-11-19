<?php

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Service\BinarySwapper;
use PHPUnit\Framework\TestCase;

class BinarySwapperTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/stud-binary-swapper-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testBackupCopiesBinaryAndKeepsOriginal(): void
    {
        file_put_contents($this->dir . '/stud', 'old binary');
        $recorder = new WorkflowRecorder();

        $result = (new BinarySwapper())->backup($recorder, $this->dir . '/stud', $this->dir . '/stud-1.0.0.bak');

        $this->assertTrue($result);
        $this->assertSame('old binary', file_get_contents($this->dir . '/stud-1.0.0.bak'));
        $this->assertSame('old binary', file_get_contents($this->dir . '/stud'));
        $this->assertSame([], $recorder->getEntries());
    }

    public function testBackupRecordsErrorWhenCopyFails(): void
    {
        $recorder = new WorkflowRecorder();

        $result = (new BinarySwapper())->backup($recorder, $this->dir . '/missing', $this->dir . '/missing-1.0.0.bak');

        $this->assertFalse($result);
        $entries = $recorder->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]->type);
        $message = $entries[0]->message;
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame('update.error_backup', $message->key);
        $this->assertStringContainsString('copy(', (string) $message->parameters['error']);
    }

    public function testActivateMovesNewBinaryIntoPlaceAsExecutable(): void
    {
        file_put_contents($this->dir . '/stud', 'old binary');
        file_put_contents($this->dir . '/stud.phar.new', 'new binary');
        $recorder = new WorkflowRecorder();

        $result = (new BinarySwapper())->activate($recorder, $this->dir . '/stud.phar.new', $this->dir . '/stud', $this->dir . '/stud-1.0.0.bak');

        $this->assertTrue($result);
        $this->assertSame('new binary', file_get_contents($this->dir . '/stud'));
        $this->assertFileDoesNotExist($this->dir . '/stud.phar.new');
        $this->assertSame(0755, fileperms($this->dir . '/stud') & 0777);
        $this->assertSame([], $recorder->getEntries());
    }

    public function testActivateReturnsFalseAndRestoresBackupWhenRenameFails(): void
    {
        file_put_contents($this->dir . '/stud', 'live binary');
        file_put_contents($this->dir . '/stud-1.0.0.bak', 'backup binary');
        $recorder = new WorkflowRecorder();

        $result = (new BinarySwapper())->activate(
            $recorder,
            $this->dir . '/missing.phar',
            $this->dir . '/stud',
            $this->dir . '/stud-1.0.0.bak',
        );

        $this->assertFalse($result);
        $this->assertSame('backup binary', file_get_contents($this->dir . '/stud'));
        $this->assertFileDoesNotExist($this->dir . '/stud-1.0.0.bak');
        $entries = $recorder->getEntries();
        $this->assertCount(1, $entries);
        $message = $entries[0]->message;
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame('update.error_rollback', $message->key);
    }

    public function testActivateReturnsFalseWhenExecutableModeCannotBeSet(): void
    {
        file_put_contents($this->dir . '/stud', 'live binary');
        file_put_contents($this->dir . '/stud.phar.new', 'new binary');
        file_put_contents($this->dir . '/stud-1.0.0.bak', 'backup binary');
        $recorder = new WorkflowRecorder();
        $swapper = new class () extends BinarySwapper {
            protected function markExecutable(string $path): bool
            {
                return false;
            }
        };

        $result = $swapper->activate(
            $recorder,
            $this->dir . '/stud.phar.new',
            $this->dir . '/stud',
            $this->dir . '/stud-1.0.0.bak',
        );

        $this->assertFalse($result);
        $this->assertSame('backup binary', file_get_contents($this->dir . '/stud'));
        $message = $recorder->getEntries()[0]->message;
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame('update.error_rollback', $message->key);
    }

    public function testActivateReportsRollbackFailureWhenBackupCannotBeRestored(): void
    {
        file_put_contents($this->dir . '/stud', 'live binary');
        $recorder = new WorkflowRecorder();
        $backupPath = $this->dir . '/missing-dir/stud.bak';

        $result = (new BinarySwapper())->activate(
            $recorder,
            $this->dir . '/missing.phar',
            $this->dir . '/stud',
            $backupPath,
        );

        $this->assertFalse($result);
        $this->assertSame('live binary', file_get_contents($this->dir . '/stud'));
        $message = $recorder->getEntries()[0]->message;
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame('update.error_rollback_failed', $message->key);
        $this->assertSame($backupPath, $message->parameters['backup_path']);
    }
}
