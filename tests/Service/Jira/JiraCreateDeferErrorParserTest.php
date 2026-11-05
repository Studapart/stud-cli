<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

use App\Exception\ApiException;
use App\Service\Jira\JiraCreateDeferErrorParser;
use PHPUnit\Framework\TestCase;

class JiraCreateDeferErrorParserTest extends TestCase
{
    private JiraCreateDeferErrorParser $parser;

    protected function setUp(): void
    {
        $this->parser = new JiraCreateDeferErrorParser();
    }

    public function testParsesScreenRejectionAfterHttpPrefix(): void
    {
        $exception = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errorMessages":[],"errors":{"labels":"Field \'labels\' cannot be set. It is not on the appropriate screen, or unknown."}}',
            400,
        );

        $errors = $this->parser->parseDeferrableFieldErrors($exception);

        $this->assertArrayHasKey('labels', $errors ?? []);
    }

    public function testIgnoresBareUnknownAndNon400(): void
    {
        $unknown = new ApiException('Could not create issue.', 'HTTP 400: {"errors":{"summary":"unknown"}}', 400);
        $this->assertNull($this->parser->parseDeferrableFieldErrors($unknown));

        $server = new ApiException('Could not create issue.', 'HTTP 500: {"errors":{"labels":"cannot be set"}}', 500);
        $this->assertNull($this->parser->parseDeferrableFieldErrors($server));
    }

    public function testReturnsNullWhenBodyIsTruncated(): void
    {
        $exception = new ApiException('Could not create issue.', 'HTTP 400: {"errors":', 400);

        $this->assertNull($this->parser->parseDeferrableFieldErrors($exception));
    }

    public function testReturnsNullWhenAnyErrorIsNotDeferrable(): void
    {
        $mixed = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"labels":"cannot be set","summary":"is required"}}',
            400,
        );

        $this->assertNull($this->parser->parseDeferrableFieldErrors($mixed));
    }

    public function testParsesScreenPhraseWithoutCannotBeSet(): void
    {
        $exception = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"labels":"The field is not on the appropriate screen."}}',
            400,
        );

        $errors = $this->parser->parseDeferrableFieldErrors($exception);

        $this->assertArrayHasKey('labels', $errors ?? []);
    }
}
