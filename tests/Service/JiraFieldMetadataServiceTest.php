<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\JiraFieldMetadataService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class JiraFieldMetadataServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testGetCreateMetaFieldsUsesFieldIdInsteadOfListIndex(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'fields' => [
                12 => [
                    'required' => false,
                    'name' => 'Labels',
                    'fieldId' => 'labels',
                    'key' => 'labels',
                ],
                1 => 'skip',
                3 => [
                    'required' => false,
                    'name' => 'Team',
                    'fieldId' => 'customfield_10001',
                ],
            ],
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $fields = (new JiraFieldMetadataService($this->httpClient))->getCreateMetaFields('SCI', '10001');

        $this->assertSame(['required' => false, 'name' => 'Labels'], $fields['labels']);
        $this->assertSame(['required' => false, 'name' => 'Team'], $fields['customfield_10001']);
        $this->assertArrayNotHasKey(12, $fields);
    }

    public function testGetCreateMetaFieldsKeepsLegacyStringKeys(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'fields' => [
                'summary' => ['required' => true, 'name' => 'Summary'],
            ],
        ]);
        $this->httpClient->method('request')->willReturn($response);

        $fields = (new JiraFieldMetadataService($this->httpClient))->getCreateMetaFields('SCI', '10001');

        $this->assertSame(['required' => true, 'name' => 'Summary'], $fields['summary']);
    }

    public function testGetCreateMetaFieldsThrowsWithTruncatedBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getContent')->with(false)->willReturn(str_repeat('x', 600));

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $service = new JiraFieldMetadataService($this->httpClient);

        try {
            $service->getCreateMetaFields('PROJ', '1');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('... (truncated)', $e->getTechnicalDetails());
        }
    }

    public function testGetEditMetaFieldsThrowsWhenBodyUnreadable(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->with(false)->willThrowException(new \RuntimeException('read failed'));

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $service = new JiraFieldMetadataService($this->httpClient);

        try {
            $service->getEditMetaFields('SCI-1');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Unable to read response body', $e->getTechnicalDetails());
        }
    }
}
