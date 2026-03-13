<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\ConfluenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ConfluenceServiceTest extends TestCase
{
    private ConfluenceService $confluenceService;
    private HttpClientInterface&MockObject $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->confluenceService = new ConfluenceService($this->httpClientMock);
    }

    public function testGetSpacesReturnsMappedList(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [
                ['id' => '1', 'key' => 'DEV', 'name' => 'Development'],
                ['id' => '2', 'key' => 'DOC', 'name' => 'Documentation'],
            ],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/spaces?limit=50')
            ->willReturn($responseMock);

        $spaces = $this->confluenceService->getSpaces();

        self::assertCount(2, $spaces);
        self::assertSame('1', $spaces[0]['id']);
        self::assertSame('DEV', $spaces[0]['key']);
        self::assertSame('Development', $spaces[0]['name']);
        self::assertSame('2', $spaces[1]['id']);
        self::assertSame('DOC', $spaces[1]['key']);
    }

    public function testGetSpacesThrowsOnNon200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(401);
        $responseMock->method('getContent')->with(false)->willReturn('Unauthorized');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/spaces?limit=50')
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to fetch Confluence spaces.');

        $this->confluenceService->getSpaces();
    }

    public function testResolveSpaceIdReturnsIdWhenKeyFound(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [
                ['id' => '42', 'key' => 'DEV', 'name' => 'Development'],
            ],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/spaces?limit=50&keys=DEV')
            ->willReturn($responseMock);

        $spaceId = $this->confluenceService->resolveSpaceId('DEV');

        self::assertSame('42', $spaceId);
    }

    public function testResolveSpaceIdIsCaseInsensitive(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [
                ['id' => '42', 'key' => 'DEV', 'name' => 'Development'],
            ],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/spaces?limit=50&keys=dev')
            ->willReturn($responseMock);

        $spaceId = $this->confluenceService->resolveSpaceId('dev');

        self::assertSame('42', $spaceId);
    }

    public function testResolveSpaceIdThrowsWhenNotFound(): void
    {
        $emptyResponse = $this->createMock(ResponseInterface::class);
        $emptyResponse->method('getStatusCode')->willReturn(200);
        $emptyResponse->method('toArray')->willReturn(['results' => []]);

        $spacesResponse = $this->createMock(ResponseInterface::class);
        $spacesResponse->method('getStatusCode')->willReturn(200);
        $spacesResponse->method('toArray')->willReturn([
            'results' => [
                ['id' => '42', 'key' => 'DEV', 'name' => 'Development'],
            ],
        ]);
        $spacesResponse->method('getHeaders')->with(false)->willReturn(['link' => []]);

        $this->httpClientMock->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($emptyResponse, $spacesResponse) {
                if (str_contains($url, 'keys=OTHER')) {
                    return $emptyResponse;
                }

                return $spacesResponse;
            });

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Confluence space "OTHER" not found.');

        $this->confluenceService->resolveSpaceId('OTHER');
    }

    public function testCreatePageSendsCorrectPayloadAndReturnsMapped(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn([
            'id' => '12345',
            'title' => 'My Page',
            '_links' => ['webui' => '/spaces/DEV/pages/12345'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'api/v2/pages',
                self::callback(function (array $options): bool {
                    $json = $options['json'];

                    return $json['spaceId'] === '1'
                        && $json['title'] === 'My Page'
                        && $json['status'] === 'current'
                        && $json['body']['representation'] === 'atlas_doc_format'
                        && $json['body']['value'] === '{"type":"doc","version":1,"content":[]}'
                        && ! isset($json['parentId']);
                })
            )
            ->willReturn($responseMock);

        $result = $this->confluenceService->createPage(
            '1',
            'My Page',
            '{"type":"doc","version":1,"content":[]}'
        );

        self::assertSame('12345', $result['id']);
        self::assertSame('My Page', $result['title']);
        self::assertSame('/spaces/DEV/pages/12345', $result['_links']['webui']);
    }

    public function testCreatePageWithParentId(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn([
            'id' => '67890',
            'title' => 'Child',
            '_links' => ['webui' => '/spaces/DEV/pages/67890'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'api/v2/pages',
                self::callback(function (array $options): bool {
                    return ($options['json']['parentId'] ?? null) === '67890';
                })
            )
            ->willReturn($responseMock);

        $result = $this->confluenceService->createPage(
            '1',
            'Child',
            '{}',
            '67890'
        );

        self::assertSame('67890', $result['id']);
    }

    public function testGetPageReturnsMappedWithVersion(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'id' => '12345',
            'title' => 'Existing',
            'version' => ['number' => 3],
            '_links' => ['webui' => '/spaces/DEV/pages/12345'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/pages/12345')
            ->willReturn($responseMock);

        $result = $this->confluenceService->getPage('12345');

        self::assertSame('12345', $result['id']);
        self::assertSame('Existing', $result['title']);
        self::assertSame(3, $result['version']['number']);
        self::assertSame('/spaces/DEV/pages/12345', $result['_links']['webui']);
    }

    public function testGetPageThrowsOn404(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn('Not found');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/pages/99999')
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Confluence page "99999" not found.');

        $this->confluenceService->getPage('99999');
    }

    public function testCreatePageThrowsOnNon201(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(400);
        $responseMock->method('getContent')->with(false)->willReturn('Bad request');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('POST', 'api/v2/pages', self::anything())
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not create Confluence page.');

        $this->confluenceService->createPage('1', 'Title', '{}');
    }

    public function testUpdatePageThrowsOnNon200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(409);
        $responseMock->method('getContent')->with(false)->willReturn('Conflict');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('PUT', 'api/v2/pages/12345', self::anything())
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not update Confluence page "12345".');

        $this->confluenceService->updatePage('12345', 'Title', '{}', 2);
    }

    public function testUpdatePageSendsCorrectPayloadAndReturnsMapped(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'id' => '12345',
            'title' => 'Updated Title',
            '_links' => ['webui' => '/spaces/DEV/pages/12345'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with(
                'PUT',
                'api/v2/pages/12345',
                self::callback(function (array $options): bool {
                    $json = $options['json'];

                    return $json['id'] === '12345'
                        && $json['title'] === 'Updated Title'
                        && $json['version']['number'] === 4
                        && $json['body']['representation'] === 'atlas_doc_format';
                })
            )
            ->willReturn($responseMock);

        $result = $this->confluenceService->updatePage(
            '12345',
            'Updated Title',
            '{"type":"doc","version":1,"content":[]}',
            4
        );

        self::assertSame('12345', $result['id']);
        self::assertSame('Updated Title', $result['title']);
    }

    public function testExtractTechnicalDetailsWhenGetContentThrows(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->with(false)->willThrowException(new \RuntimeException('Network error'));

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/spaces?limit=50')
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to fetch Confluence spaces.');

        $this->confluenceService->getSpaces();
    }

    public function testExtractTechnicalDetailsTruncatesLongBody(): void
    {
        $longBody = str_repeat('x', 600);
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->with(false)->willReturn($longBody);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/spaces?limit=50')
            ->willReturn($responseMock);

        try {
            $this->confluenceService->getSpaces();
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertStringContainsString('(truncated)', $e->getTechnicalDetails());
        }
    }

    public function testGetFolderReturnsMapped(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'id' => '5315756039',
            'title' => 'Research',
            'spaceId' => '42',
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/folders/5315756039')
            ->willReturn($responseMock);

        $result = $this->confluenceService->getFolder('5315756039');

        self::assertSame('5315756039', $result['id']);
        self::assertSame('Research', $result['title']);
        self::assertSame('42', $result['spaceId']);
    }

    public function testGetFolderThrowsOnNon200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn('Not found');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/folders/99999')
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Confluence folder "99999" not found.');

        $this->confluenceService->getFolder('99999');
    }

    public function testGetDirectChildPagesOfFolderReturnsPages(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [
                ['id' => '100', 'title' => 'Child A', 'type' => 'page'],
                ['id' => '101', 'title' => 'Child B', 'type' => 'page'],
                ['id' => '102', 'title' => 'Blog', 'type' => 'blogpost'],
            ],
        ]);
        $responseMock->method('getHeaders')->with(false)->willReturn(['link' => []]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/folders/5315756039/direct-children?limit=50')
            ->willReturn($responseMock);

        $result = $this->confluenceService->getDirectChildPagesOfFolder('5315756039');

        self::assertCount(2, $result);
        self::assertSame('100', $result[0]['id']);
        self::assertSame('Child A', $result[0]['title']);
        self::assertSame('101', $result[1]['id']);
        self::assertSame('Child B', $result[1]['title']);
    }

    public function testAddPageLabelsSendsCorrectPayload(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'rest/api/content/12345/label',
                self::callback(function (array $options): bool {
                    $json = $options['json'] ?? null;

                    return is_array($json) && $json === [
                        ['prefix' => 'global', 'name' => 'research'],
                        ['prefix' => 'global', 'name' => 'DX'],
                    ];
                })
            )
            ->willReturn($responseMock);

        $this->confluenceService->addPageLabels('12345', ['research', 'DX']);
    }

    public function testCreatePageAccepts200StatusCode(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'id' => '999',
            'title' => 'Under Folder',
            '_links' => ['webui' => '/spaces/PROD/pages/999'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('POST', 'api/v2/pages', self::anything())
            ->willReturn($responseMock);

        $result = $this->confluenceService->createPage('1', 'Under Folder', '{}');

        self::assertSame('999', $result['id']);
        self::assertSame('Under Folder', $result['title']);
    }

    public function testGetDirectChildPagesReturnsPagesAndSkipsNonPageTypes(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [
                ['id' => '100', 'title' => 'Child A', 'type' => 'page'],
                ['id' => '101', 'title' => 'Blog', 'type' => 'blogpost'],
                ['id' => '102', 'title' => 'Child B', 'type' => 'page'],
            ],
        ]);
        $responseMock->method('getHeaders')->with(false)->willReturn(['link' => []]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/pages/parent123/direct-children?limit=50')
            ->willReturn($responseMock);

        $result = $this->confluenceService->getDirectChildPages('parent123');

        self::assertCount(2, $result);
        self::assertSame('100', $result[0]['id']);
        self::assertSame('Child A', $result[0]['title']);
        self::assertSame('102', $result[1]['id']);
        self::assertSame('Child B', $result[1]['title']);
    }

    public function testGetDirectChildPagesThrowsOnNon200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->with(false)->willReturn('Server error');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/pages/parent123/direct-children?limit=50')
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to fetch child pages.');

        $this->confluenceService->getDirectChildPages('parent123');
    }

    public function testGetDirectChildPagesWithEmptyNextLinkUrlReturnsFirstPageOnly(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [['id' => '1', 'title' => 'Only', 'type' => 'page']],
        ]);
        $responseMock->method('getHeaders')->with(false)->willReturn([
            'link' => ['<>; rel="next"'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/pages/parent/direct-children?limit=50')
            ->willReturn($responseMock);

        $result = $this->confluenceService->getDirectChildPages('parent');

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]['id']);
    }

    public function testGetDirectChildPagesWithPaginationFollowsNextLink(): void
    {
        $page1Response = $this->createMock(ResponseInterface::class);
        $page1Response->method('getStatusCode')->willReturn(200);
        $page1Response->method('toArray')->willReturn([
            'results' => [['id' => '1', 'title' => 'First', 'type' => 'page']],
        ]);
        $page1Response->method('getHeaders')->with(false)->willReturn([
            'link' => ['<https://wiki.example.com/api/v2/pages/parent/direct-children?limit=50&cursor=next>; rel="next"'],
        ]);

        $page2Response = $this->createMock(ResponseInterface::class);
        $page2Response->method('getStatusCode')->willReturn(200);
        $page2Response->method('toArray')->willReturn([
            'results' => [['id' => '2', 'title' => 'Second', 'type' => 'page']],
        ]);
        $page2Response->method('getHeaders')->with(false)->willReturn(['link' => []]);

        $this->httpClientMock->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($page1Response, $page2Response) {
                if (str_contains($url, 'cursor=next') || str_contains($url, 'https://wiki.example.com')) {
                    return $page2Response;
                }

                return $page1Response;
            });

        $result = $this->confluenceService->getDirectChildPages('parent');

        self::assertCount(2, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('2', $result[1]['id']);
    }

    public function testGetDirectChildPagesOfFolderWithPaginationFollowsNextLink(): void
    {
        $page1Response = $this->createMock(ResponseInterface::class);
        $page1Response->method('getStatusCode')->willReturn(200);
        $page1Response->method('toArray')->willReturn([
            'results' => [['id' => '1', 'title' => 'First', 'type' => 'page']],
        ]);
        $page1Response->method('getHeaders')->with(false)->willReturn([
            'link' => ['<https://wiki.example.com/api/v2/folders/f1/direct-children?limit=50&cursor=next>; rel="next"'],
        ]);

        $page2Response = $this->createMock(ResponseInterface::class);
        $page2Response->method('getStatusCode')->willReturn(200);
        $page2Response->method('toArray')->willReturn([
            'results' => [['id' => '2', 'title' => 'Second', 'type' => 'page']],
        ]);
        $page2Response->method('getHeaders')->with(false)->willReturn(['link' => []]);

        $this->httpClientMock->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($page1Response, $page2Response) {
                if (str_contains($url, 'cursor=next') || str_contains($url, 'https://wiki.example.com')) {
                    return $page2Response;
                }

                return $page1Response;
            });

        $result = $this->confluenceService->getDirectChildPagesOfFolder('f1');

        self::assertCount(2, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('2', $result[1]['id']);
    }

    public function testGetDirectChildPagesOfFolderThrowsOnNon200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(403);
        $responseMock->method('getContent')->with(false)->willReturn('Forbidden');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/folders/f1/direct-children?limit=50')
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to fetch folder children.');

        $this->confluenceService->getDirectChildPagesOfFolder('f1');
    }

    public function testResolveSpaceIdReturnsNumericIdWhenKeyIsAllDigits(): void
    {
        $spaceId = $this->confluenceService->resolveSpaceId('42');

        self::assertSame('42', $spaceId);
    }

    public function testResolveSpaceIdThrowsWhenSpaceKeyEmpty(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Confluence space "   " not found.');

        $this->confluenceService->resolveSpaceId('   ');
    }

    public function testResolveSpaceIdUsesGetSpacesFallbackWhenKeyNotInGetSpacesByKeys(): void
    {
        $emptyResponse = $this->createMock(ResponseInterface::class);
        $emptyResponse->method('getStatusCode')->willReturn(200);
        $emptyResponse->method('toArray')->willReturn(['results' => []]);

        $spacesResponse = $this->createMock(ResponseInterface::class);
        $spacesResponse->method('getStatusCode')->willReturn(200);
        $spacesResponse->method('toArray')->willReturn([
            'results' => [['id' => '99', 'key' => 'FALLBACK', 'name' => 'Fallback Space']],
        ]);
        $spacesResponse->method('getHeaders')->with(false)->willReturn(['link' => []]);

        $this->httpClientMock->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($emptyResponse, $spacesResponse) {
                if (str_contains($url, 'keys=FALLBACK')) {
                    return $emptyResponse;
                }

                return $spacesResponse;
            });

        $spaceId = $this->confluenceService->resolveSpaceId('FALLBACK');

        self::assertSame('99', $spaceId);
    }

    public function testAddPageLabelsThrowsOnNon200AndNon201(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(400);
        $responseMock->method('getContent')->with(false)->willReturn('Bad request');

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('POST', 'rest/api/content/12345/label', self::anything())
            ->willReturn($responseMock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not add labels to Confluence page.');

        $this->confluenceService->addPageLabels('12345', ['research']);
    }

    public function testGetSpacesByKeysReturnsEmptyWhenKeysEmpty(): void
    {
        $this->httpClientMock->expects(self::never())->method('request');

        $result = $this->confluenceService->getSpacesByKeys([]);

        self::assertSame([], $result);
    }

    public function testGetSpacesByKeysReturnsEmptyOnNon200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', self::stringContains('api/v2/spaces'))
            ->willReturn($responseMock);

        $result = $this->confluenceService->getSpacesByKeys(['DEV']);

        self::assertSame([], $result);
    }

    public function testGetSpacesByKeysReturnsMappedResultsWhen200(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'results' => [
                ['id' => '1', 'key' => 'DEV', 'name' => 'Development'],
            ],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', self::stringContains('keys=DEV'))
            ->willReturn($responseMock);

        $result = $this->confluenceService->getSpacesByKeys(['DEV']);

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('DEV', $result[0]['key']);
    }

    public function testGetPageUsesSpaceIdFromNestedSpaceWhenSpaceIdMissing(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'id' => '123',
            'title' => 'Page',
            'version' => ['number' => 1],
            '_links' => ['webui' => '/pages/123'],
            'space' => ['id' => '99'],
        ]);

        $this->httpClientMock->expects(self::once())
            ->method('request')
            ->with('GET', 'api/v2/pages/123')
            ->willReturn($responseMock);

        $result = $this->confluenceService->getPage('123');

        self::assertSame('99', $result['spaceId']);
    }

    public function testAddPageLabelsNoOpWhenLabelNamesEmpty(): void
    {
        $this->httpClientMock->expects(self::never())->method('request');

        $this->confluenceService->addPageLabels('12345', []);
    }
}
