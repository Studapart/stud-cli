<?php

namespace App\Tests\Service;

use App\Service\DurationParser;
use App\Service\FieldsParser;
use PHPUnit\Framework\TestCase;

class FieldsParserTest extends TestCase
{
    private FieldsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FieldsParser(new DurationParser());
    }

    public function testParseValidFieldsString(): void
    {
        $result = $this->parser->parse('labels=AI-Generated,DX;timeoriginalestimate=1d');
        $this->assertSame(['AI-Generated', 'DX'], $result['labels']);
        $this->assertSame('1d', $result['timeoriginalestimate']);
    }

    public function testParseEmptyString(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('   '));
    }

    public function testParseSinglePair(): void
    {
        $result = $this->parser->parse('priority=High');
        $this->assertSame(['priority' => 'High'], $result);
    }

    public function testParseValueContainingEquals(): void
    {
        $result = $this->parser->parse('customfield=a=b');
        $this->assertSame('a=b', $result['customfield']);
    }

    public function testParseTrailingSemicolon(): void
    {
        $result = $this->parser->parse('labels=Bug;');
        $this->assertSame(['labels' => 'Bug'], $result);
    }

    public function testParseWhitespace(): void
    {
        $result = $this->parser->parse('  labels = Bug , Fix ;  priority = High  ');
        $this->assertSame(['Bug', 'Fix'], $result['labels']);
        $this->assertSame('High', $result['priority']);
    }

    public function testParseSkipsMalformedPairs(): void
    {
        $result = $this->parser->parse('noequals;labels=Bug');
        $this->assertSame(['labels' => 'Bug'], $result);
    }

    public function testParseSkipsEmptyKey(): void
    {
        $result = $this->parser->parse('=value;labels=Bug');
        $this->assertSame(['labels' => 'Bug'], $result);
    }

    public function testMatchAndTransformLabels(): void
    {
        $meta = ['labels' => ['required' => false, 'name' => 'Labels']];
        $result = $this->parser->matchAndTransform(['labels' => ['AI', 'DX']], $meta);

        $this->assertSame(['AI', 'DX'], $result['matched']['labels']);
        $this->assertSame([], $result['unmatched']);
    }

    public function testMatchAndTransformLabelsFromScalar(): void
    {
        $meta = ['labels' => ['required' => false, 'name' => 'Labels']];
        $result = $this->parser->matchAndTransform(['labels' => 'Bug'], $meta);

        $this->assertSame(['Bug'], $result['matched']['labels']);
    }

    public function testMatchAndTransformTimeEstimate(): void
    {
        $meta = ['timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate']];
        $result = $this->parser->matchAndTransform(['timeoriginalestimate' => '2h'], $meta);

        $this->assertSame(7200, $result['matched']['timeoriginalestimate']);
    }

    public function testMatchAndTransformTimeEstimateInvalidFallback(): void
    {
        $meta = ['timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate']];
        $result = $this->parser->matchAndTransform(['timeoriginalestimate' => 'invalid'], $meta);

        $this->assertSame('invalid', $result['matched']['timeoriginalestimate']);
    }

    public function testMatchAndTransformParent(): void
    {
        $meta = ['parent' => ['required' => false, 'name' => 'Parent']];
        $result = $this->parser->matchAndTransform(['parent' => 'PROJ-123'], $meta);

        $this->assertSame(['key' => 'PROJ-123'], $result['matched']['parent']);
    }

    public function testMatchAndTransformParentFromArray(): void
    {
        $meta = ['parent' => ['required' => false, 'name' => 'Parent']];
        $result = $this->parser->matchAndTransform(['parent' => ['PROJ-123']], $meta);

        $this->assertSame(['key' => 'PROJ-123'], $result['matched']['parent']);
    }

    public function testMatchAndTransformAssignee(): void
    {
        $meta = ['assignee' => ['required' => false, 'name' => 'Assignee']];
        $result = $this->parser->matchAndTransform(['assignee' => 'abc123'], $meta);

        $this->assertSame(['id' => 'abc123'], $result['matched']['assignee']);
    }

    public function testMatchAndTransformReporter(): void
    {
        $meta = ['reporter' => ['required' => false, 'name' => 'Reporter']];
        $result = $this->parser->matchAndTransform(['reporter' => 'user1'], $meta);

        $this->assertSame(['id' => 'user1'], $result['matched']['reporter']);
    }

    public function testMatchAndTransformPriority(): void
    {
        $meta = ['priority' => ['required' => false, 'name' => 'Priority']];
        $result = $this->parser->matchAndTransform(['priority' => 'High'], $meta);

        $this->assertSame(['name' => 'High'], $result['matched']['priority']);
    }

    public function testMatchAndTransformFixVersions(): void
    {
        $meta = ['fixVersions' => ['required' => false, 'name' => 'Fix versions']];
        $result = $this->parser->matchAndTransform(['fixVersions' => ['v1.0', 'v2.0']], $meta);

        $this->assertSame([['name' => 'v1.0'], ['name' => 'v2.0']], $result['matched']['fixVersions']);
    }

    public function testMatchAndTransformFixVersionsFromScalar(): void
    {
        $meta = ['fixVersions' => ['required' => false, 'name' => 'Fix versions']];
        $result = $this->parser->matchAndTransform(['fixVersions' => 'v1.0'], $meta);

        $this->assertSame([['name' => 'v1.0']], $result['matched']['fixVersions']);
    }

    public function testMatchAndTransformVersions(): void
    {
        $meta = ['versions' => ['required' => false, 'name' => 'Affects versions']];
        $result = $this->parser->matchAndTransform(['versions' => 'v1.0'], $meta);

        $this->assertSame([['name' => 'v1.0']], $result['matched']['versions']);
    }

    public function testMatchAndTransformUnknownPassthrough(): void
    {
        $meta = ['customfield_10001' => ['required' => false, 'name' => 'Team']];
        $result = $this->parser->matchAndTransform(['customfield_10001' => 'Alpha'], $meta);

        $this->assertSame('Alpha', $result['matched']['customfield_10001']);
    }

    public function testMatchAndTransformUnmatchedFields(): void
    {
        $meta = ['labels' => ['required' => false, 'name' => 'Labels']];
        $result = $this->parser->matchAndTransform(['labels' => 'Bug', 'unknown' => 'val'], $meta);

        $this->assertSame(['Bug'], $result['matched']['labels']);
        $this->assertSame(['unknown'], $result['unmatched']);
    }

    public function testMatchByFieldNameCaseInsensitive(): void
    {
        $meta = ['timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate']];
        $result = $this->parser->matchAndTransform(['Time Original Estimate' => '1d'], $meta);

        $this->assertSame(86400, $result['matched']['timeoriginalestimate']);
    }

    public function testMatchByFieldIdCaseInsensitive(): void
    {
        $meta = ['Labels' => ['required' => false, 'name' => 'Labels']];
        $result = $this->parser->matchAndTransform(['labels' => 'Bug'], $meta);

        $this->assertSame(['Bug'], $result['matched']['Labels']);
    }

    public function testToPayloadKeyWithNumericId(): void
    {
        $meta = ['10001' => ['required' => false, 'name' => 'Custom']];
        $parsed = $this->parser->parse('10001=value');
        $result = $this->parser->matchAndTransform($parsed, $meta);

        $this->assertSame('value', $result['matched']['customfield_10001']);
    }

    public function testToPayloadKeyWithCustomfieldPrefix(): void
    {
        $meta = ['customfield_99' => ['required' => false, 'name' => 'Foo']];
        $result = $this->parser->matchAndTransform(['customfield_99' => 'bar'], $meta);

        $this->assertSame('bar', $result['matched']['customfield_99']);
    }

    public function testAssigneeFromArray(): void
    {
        $meta = ['assignee' => ['required' => false, 'name' => 'Assignee']];
        $result = $this->parser->matchAndTransform(['assignee' => ['user1']], $meta);

        $this->assertSame(['id' => 'user1'], $result['matched']['assignee']);
    }

    public function testPriorityFromArray(): void
    {
        $meta = ['priority' => ['required' => false, 'name' => 'Priority']];
        $result = $this->parser->matchAndTransform(['priority' => ['High']], $meta);

        $this->assertSame(['name' => 'High'], $result['matched']['priority']);
    }

    public function testTimeEstimateFromArray(): void
    {
        $meta = ['timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate']];
        $result = $this->parser->matchAndTransform(['timeoriginalestimate' => ['30m']], $meta);

        $this->assertSame(1800, $result['matched']['timeoriginalestimate']);
    }

    public function testTimetrackingTransformed(): void
    {
        $meta = ['timetracking' => ['required' => false, 'name' => 'Time Tracking']];
        $result = $this->parser->matchAndTransform(['timetracking' => '1h'], $meta);

        $this->assertSame(3600, $result['matched']['timetracking']);
    }
}
