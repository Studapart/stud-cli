<?php

namespace App\Tests\Response;

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
}
