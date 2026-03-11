<?php

namespace App\Tests\Service;

use App\Service\DurationParser;
use App\Service\IssueFieldResolver;
use App\Tests\CommandTestCase;

class IssueFieldResolverTest extends CommandTestCase
{
    private DurationParser $durationParser;
    private IssueFieldResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->durationParser = new DurationParser();
        $this->resolver = new IssueFieldResolver($this->jiraService, $this->durationParser);
    }

    public function testResolveIssueTypeNameReturnsSubTaskWhenParentKey(): void
    {
        $this->assertSame('Sub-task', $this->resolver->resolveIssueTypeName(null, 'PROJ-100'));
        $this->assertSame('Sub-task', $this->resolver->resolveIssueTypeName('Story', 'PROJ-100'));
    }

    public function testResolveIssueTypeNameReturnsTypeWhenProvided(): void
    {
        $this->assertSame('Bug', $this->resolver->resolveIssueTypeName('Bug', null));
        $this->assertSame('Task', $this->resolver->resolveIssueTypeName('Task', null));
    }

    public function testResolveIssueTypeNameDefaultsToStory(): void
    {
        $this->assertSame('Story', $this->resolver->resolveIssueTypeName(null, null));
        $this->assertSame('Story', $this->resolver->resolveIssueTypeName('', null));
    }

    public function testResolveIssueTypeIdReturnsIdWhenFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story'], ['id' => '10002', 'name' => 'Bug']]);

        $this->assertSame('10002', $this->resolver->resolveIssueTypeId('PROJ', 'Bug'));
    }

    public function testResolveIssueTypeIdReturnsNullWhenNotFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);

        $this->assertNull($this->resolver->resolveIssueTypeId('PROJ', 'Epic'));
    }

    public function testResolveIssueTypeIdReturnsNullWhenApiThrows(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->willThrowException(new \RuntimeException('API error'));

        $this->assertNull($this->resolver->resolveIssueTypeId('PROJ', 'Story'));
    }

    public function testBuildBaseFieldsCreatesMinimalFields(): void
    {
        $fields = $this->resolver->buildBaseFields('PROJ', '10001', 'Summary', null, null, null);

        $this->assertSame(['key' => 'PROJ'], $fields['project']);
        $this->assertSame(['id' => '10001'], $fields['issuetype']);
        $this->assertSame('Summary', $fields['summary']);
        $this->assertArrayNotHasKey('description', $fields);
        $this->assertArrayNotHasKey('parent', $fields);
    }

    public function testBuildBaseFieldsIncludesParent(): void
    {
        $fields = $this->resolver->buildBaseFields('PROJ', '10001', 'Summary', null, null, 'PROJ-100');

        $this->assertSame(['key' => 'PROJ-100'], $fields['parent']);
    }

    public function testBuildBaseFieldsIncludesDescription(): void
    {
        $this->jiraService->expects($this->once())
            ->method('descriptionToAdf')
            ->with('desc text', 'plain')
            ->willReturn(['type' => 'doc', 'content' => []]);

        $fields = $this->resolver->buildBaseFields('PROJ', '10001', 'Summary', 'desc text', null, null);

        $this->assertSame(['type' => 'doc', 'content' => []], $fields['description']);
    }

    public function testGetRequiredFieldIdsFromMeta(): void
    {
        $meta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'labels' => ['required' => false, 'name' => 'Labels'],
            'summary' => ['required' => true, 'name' => 'Summary'],
        ];

        $result = $this->resolver->getRequiredFieldIdsFromMeta($meta);

        $this->assertSame(['project', 'summary'], $result);
    }

    public function testDefaultAssigneeWhenFieldPresent(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('user-123');

        $meta = ['assignee' => ['required' => false, 'name' => 'Assignee']];
        $fields = [];
        $this->resolver->defaultAssigneeWhenFieldPresent($meta, $fields, null);

        $this->assertSame(['accountId' => 'user-123'], $fields['assignee']);
    }

    public function testDefaultAssigneeUsesOptionWhenProvided(): void
    {
        $this->jiraService->expects($this->never())->method('getCurrentUserAccountId');

        $meta = ['assignee' => ['required' => false, 'name' => 'Assignee']];
        $fields = [];
        $this->resolver->defaultAssigneeWhenFieldPresent($meta, $fields, 'custom-id');

        $this->assertSame(['accountId' => 'custom-id'], $fields['assignee']);
    }

    public function testDefaultAssigneeSkipsWhenAlreadySet(): void
    {
        $meta = ['assignee' => ['required' => false, 'name' => 'Assignee']];
        $fields = ['assignee' => ['accountId' => 'existing']];
        $this->resolver->defaultAssigneeWhenFieldPresent($meta, $fields, null);

        $this->assertSame(['accountId' => 'existing'], $fields['assignee']);
    }

    public function testGetCreatePayloadFieldKeyWithCustomfieldPrefix(): void
    {
        $this->assertSame('customfield_10001', $this->resolver->getCreatePayloadFieldKey('customfield_10001'));
    }

    public function testGetCreatePayloadFieldKeyWithNumericId(): void
    {
        $this->assertSame('customfield_15', $this->resolver->getCreatePayloadFieldKey('15'));
    }

    public function testGetCreatePayloadFieldKeyWithStandardField(): void
    {
        $this->assertSame('summary', $this->resolver->getCreatePayloadFieldKey('summary'));
    }

    public function testGetExtraRequiredFieldsList(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'customfield_10001' => ['name' => 'Team', 'required' => true],
                'customfield_10002' => ['name' => 'Sprint', 'required' => true],
            ]);

        $result = $this->resolver->getExtraRequiredFieldsList('PROJ', '10001', ['customfield_10001', 'customfield_10002']);

        $this->assertSame('Team (customfield_10001), Sprint (customfield_10002)', $result);
    }

    public function testGetExtraRequiredFieldsListFallsBackOnApiError(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->willThrowException(new \RuntimeException('API error'));

        $result = $this->resolver->getExtraRequiredFieldsList('PROJ', '10001', ['cf1', 'cf2']);

        $this->assertSame('cf1, cf2', $result);
    }

    public function testFillStandardFieldByNameSetsParentKey(): void
    {
        $fields = ['project' => ['key' => 'PROJ'], 'summary' => 'Summary', 'issuetype' => ['id' => '10001']];
        $fieldValues = [
            'projectKey' => 'PROJ',
            'issueTypeId' => '10001',
            'summary' => 'Summary',
            'descriptionAdf' => null,
            'assigneeOption' => null,
            'parentKey' => 'PROJ-100',
        ];

        $result = $this->callPrivateMethod($this->resolver, 'fillStandardFieldByName', [
            'parent', &$fields, $fieldValues, true,
        ]);

        $this->assertTrue($result);
        $this->assertSame(['key' => 'PROJ-100'], $fields['parent']);
    }

    public function testFillStandardFieldByNameWithUnknownFieldNameReturnsFalse(): void
    {
        $fields = ['project' => ['key' => 'PROJ'], 'summary' => 'Summary'];
        $fieldValues = [
            'projectKey' => 'PROJ',
            'issueTypeId' => '10001',
            'summary' => 'Summary',
            'descriptionAdf' => null,
            'assigneeOption' => null,
            'parentKey' => null,
        ];

        $result = $this->callPrivateMethod($this->resolver, 'fillStandardFieldByName', [
            'customfield_12345', &$fields, $fieldValues, true,
        ]);

        $this->assertFalse($result);
        $this->assertSame(['project' => ['key' => 'PROJ'], 'summary' => 'Summary'], $fields);
    }

    public function testApplyOptionalFieldsWithLabelsAndEstimate(): void
    {
        $meta = [
            'labels' => ['required' => false, 'name' => 'Labels'],
            'timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate'],
        ];
        $fields = [];

        $skipped = $this->resolver->applyOptionalFieldsFromCreatemeta($meta, $fields, 'a, b', '2h', $this->translationService);

        $this->assertSame(['a', 'b'], $fields['labels']);
        $this->assertSame(7200, $fields['timeoriginalestimate']);
        $this->assertSame([], $skipped);
    }

    public function testApplyOptionalFieldsSkipsWhenNotInMeta(): void
    {
        $meta = [];
        $fields = [];

        $skipped = $this->resolver->applyOptionalFieldsFromCreatemeta($meta, $fields, 'a', '1d', $this->translationService);

        $this->assertArrayNotHasKey('labels', $fields);
        $this->assertArrayNotHasKey('timeoriginalestimate', $fields);
        $this->assertCount(2, $skipped);
    }

    public function testResolveStandardFieldsAndExtraRequired(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('user-id');

        $allFieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'reporter' => ['required' => true, 'name' => 'Reporter'],
            'customfield_10001' => ['required' => true, 'name' => 'Team'],
        ];
        $requiredFieldIds = ['project', 'issuetype', 'summary', 'reporter', 'customfield_10001'];
        $fields = [
            'project' => ['key' => 'PROJ'],
            'issuetype' => ['id' => '10001'],
            'summary' => 'Summary',
        ];

        $fieldValues = [
            'projectKey' => 'PROJ',
            'issueTypeId' => '10001',
            'summary' => 'Summary',
            'descriptionAdf' => null,
            'assigneeOption' => null,
            'parentKey' => null,
        ];
        $extra = $this->resolver->resolveStandardFieldsAndExtraRequired(
            $requiredFieldIds,
            $allFieldsMeta,
            $fields,
            $fieldValues,
            true
        );

        $this->assertSame(['customfield_10001'], $extra);
        $this->assertSame(['accountId' => 'user-id'], $fields['reporter']);
    }
}
