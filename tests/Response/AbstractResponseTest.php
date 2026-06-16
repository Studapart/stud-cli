<?php

namespace App\Tests\Response;

use App\DTO\ResponseMessage;
use App\Response\AbstractResponse;
use PHPUnit\Framework\TestCase;

class AbstractResponseTest extends TestCase
{
    public function testIsSuccessReturnsTrueForSuccessResponse(): void
    {
        $response = new class (true) extends AbstractResponse {
            public function __construct(bool $success)
            {
                parent::__construct($success);
            }
        };

        $this->assertTrue($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForErrorResponse(): void
    {
        $response = new class (false, 'Error message') extends AbstractResponse {
            public function __construct(bool $success, ?string $error)
            {
                parent::__construct($success, $error);
            }
        };

        $this->assertFalse($response->isSuccess());
    }

    public function testGetErrorReturnsErrorWhenPresent(): void
    {
        $errorMessage = 'Test error message';
        $response = new class (false, $errorMessage) extends AbstractResponse {
            public function __construct(bool $success, ?string $error)
            {
                parent::__construct($success, $error);
            }
        };

        $this->assertSame($errorMessage, $response->getError());
    }

    public function testGetErrorReturnsNullWhenSuccess(): void
    {
        $response = new class (true) extends AbstractResponse {
            public function __construct(bool $success)
            {
                parent::__construct($success);
            }
        };

        $this->assertNull($response->getError());
    }

    public function testDiagnosticsAreGroupedByLevel(): void
    {
        $response = new class ([
            ResponseMessage::error('Failed', 'stack trace', ['command' => 'test']),
            ResponseMessage::warning('Careful'),
            ResponseMessage::notice('Skipped optional step'),
            ResponseMessage::info('Debug context'),
        ]) extends AbstractResponse {
            public function __construct(array $messages)
            {
                parent::__construct(false, 'Failed', $messages);
            }
        };

        $this->assertTrue($response->hasDiagnostics());
        $this->assertSame('Failed', $response->getErrors()[0]->message);
        $this->assertSame('Careful', $response->getWarnings()[0]->message);
        $this->assertSame('Skipped optional step', $response->getNotices()[0]->message);
        $this->assertSame('Debug context', $response->getInfos()[0]->message);
        $this->assertSame(['stack trace'], $response->getTechnicalDetails());
        $this->assertSame([
            'errors' => [['message' => 'Failed', 'technicalDetails' => 'stack trace', 'context' => ['command' => 'test']]],
            'warnings' => [['message' => 'Careful']],
            'notices' => [['message' => 'Skipped optional step']],
            'info' => [['message' => 'Debug context']],
        ], $response->diagnosticsPayload());
    }
}
