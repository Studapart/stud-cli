<?php

namespace App\Tests\Service;

use App\Service\JiraHtmlConverter;
use App\Service\Logger;
use PHPUnit\Framework\TestCase;
use Stevebauman\Hypertext\Transformer;

class JiraHtmlConverterTest extends TestCase
{
    private JiraHtmlConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new JiraHtmlConverter();
    }

    public function testToPlainTextWithSimpleHtml(): void
    {
        $html = '<p>Hello, <strong>world</strong>!</p>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function testToPlainTextWithLists(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testToPlainTextWithCodeBlocks(): void
    {
        $html = '<pre><code>function test() { return true; }</code></pre>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testToPlainTextWithHrTags(): void
    {
        $html = '<p>Before</p><hr><p>After</p>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('---', $result);
    }

    public function testToPlainTextWithHrTagsVariations(): void
    {
        $html = '<p>Before</p><hr/><p>After</p>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('---', $result);
    }

    public function testToPlainTextWithHrTagsWithAttributes(): void
    {
        $html = '<p>Before</p><hr class="divider"/><p>After</p>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('---', $result);
    }

    public function testToPlainTextWithEmptyString(): void
    {
        $result = $this->converter->toPlainText('');

        $this->assertIsString($result);
        $this->assertSame('', $result);
    }

    public function testToPlainTextWithLinks(): void
    {
        $html = '<p>Check out <a href="https://example.com">this link</a> for more info.</p>';
        $result = $this->converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testToMarkdownWithSimpleHtml(): void
    {
        $html = '<h1>Title</h1><p>Paragraph with <strong>bold</strong> text.</p>';
        $result = $this->converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('# Title', $result);
        $this->assertStringContainsString('**bold**', $result);
    }

    public function testToMarkdownPreservesFormatting(): void
    {
        $html = '<h2>Heading</h2><ul><li>Item 1</li><li>Item 2</li></ul><pre><code>code block</code></pre>';
        $result = $this->converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('## Heading', $result);
        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('```', $result);
    }

    public function testToMarkdownHandlesJiraArtifacts(): void
    {
        $html = '<p>Text with &amp; entities and <span class="jira-issue">broken</span> tags</p>';
        $result = $this->converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testToMarkdownWithLinks(): void
    {
        $html = '<p>Check out <a href="https://example.com">this link</a> for more info.</p>';
        $result = $this->converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertStringContainsString('[this link](https://example.com)', $result);
    }

    public function testToMarkdownWithEmptyString(): void
    {
        $result = $this->converter->toMarkdown('');

        $this->assertIsString($result);
        $this->assertSame('', $result);
    }

    public function testToMarkdownWithMalformedHtml(): void
    {
        $html = '<p>Unclosed tag<div>Another<div>Nested</div>';
        $result = $this->converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testToAsciiDocReturnsContentAsIs(): void
    {
        $content = '<p>Test content</p>';
        $result = $this->converter->toAsciiDoc($content);

        $this->assertIsString($result);
        $this->assertSame($content, $result);
    }

    public function testToAsciiDocWithEmptyString(): void
    {
        $result = $this->converter->toAsciiDoc('');

        $this->assertIsString($result);
        $this->assertSame('', $result);
    }

    public function testConstructorWithCustomTransformer(): void
    {
        $transformer = new Transformer();
        $converter = new JiraHtmlConverter($transformer);

        $html = '<p>Test</p>';
        $result = $converter->toPlainText($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testImplementsHtmlConverterInterface(): void
    {
        $this->assertInstanceOf(\App\Service\HtmlConverterInterface::class, $this->converter);
    }

    public function testImplementsCanConvertToPlainTextInterface(): void
    {
        $this->assertInstanceOf(\App\Service\CanConvertToPlainTextInterface::class, $this->converter);
    }

    public function testImplementsCanConvertToMarkdownInterface(): void
    {
        $this->assertInstanceOf(\App\Service\CanConvertToMarkdownInterface::class, $this->converter);
    }

    public function testImplementsCanConvertToAsciiDocInterface(): void
    {
        $this->assertInstanceOf(\App\Service\CanConvertToAsciiDocInterface::class, $this->converter);
    }

    public function testToMarkdownExceptionFallback(): void
    {
        // Test exception path by using reflection to inject a mock converter that throws
        $reflection = new \ReflectionClass($this->converter);
        $markdownConverterProperty = $reflection->getProperty('markdownConverter');
        $markdownConverterProperty->setAccessible(true);

        // Create a mock that will throw an exception
        $mockConverter = $this->createMock(\League\HTMLToMarkdown\HtmlConverter::class);
        $mockConverter->method('convert')
            ->willThrowException(new \Exception('Conversion failed'));

        // Inject the mock
        $markdownConverterProperty->setValue($this->converter, $mockConverter);

        // Now test that exception is caught and original content is returned
        $content = '<p>Test content</p>';
        $result = $this->converter->toMarkdown($content);

        // Should return original content when exception occurs
        $this->assertSame($content, $result);
    }

    public function testToMarkdownWithVeryLargeContent(): void
    {
        // Test with very large content to ensure no issues
        $html = str_repeat('<p>Test paragraph</p>', 1000);
        $result = $this->converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testToMarkdownWithLoggerWhenExtensionAvailable(): void
    {
        // Create a mock logger - should not be called when extension is available
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())
            ->method('warning');

        // Create converter with logger
        $converter = new JiraHtmlConverter(null, $logger);

        // Test that conversion works normally when extension is available
        $html = '<p>Test content</p>';
        $result = $converter->toMarkdown($html);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // When extension is available, result should be converted markdown, not original HTML
        $this->assertNotSame($html, $result);
    }

    public function testToMarkdownHandlesDOMDocumentException(): void
    {
        // Create a mock logger
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                Logger::VERBOSITY_NORMAL,
                'PHP XML extension is not available. HTML to Markdown conversion disabled. Install php-xml extension.'
            );

        // Create converter with logger
        $converter = new JiraHtmlConverter(null, $logger);

        // Use reflection to inject a mock converter that throws DOMDocument exception
        $reflection = new \ReflectionClass($converter);
        $markdownConverterProperty = $reflection->getProperty('markdownConverter');
        $markdownConverterProperty->setAccessible(true);

        // Create a mock that throws DOMDocument-related exception
        $mockConverter = $this->createMock(\League\HTMLToMarkdown\HtmlConverter::class);
        $mockConverter->method('convert')
            ->willThrowException(new \Exception("Class 'DOMDocument' not found"));

        // Inject the mock
        $markdownConverterProperty->setValue($converter, $mockConverter);

        // Test that exception is caught and original content is returned
        $content = '<p>Test content</p>';
        $result = $converter->toMarkdown($content);

        // Should return original content when DOMDocument exception occurs
        $this->assertSame($content, $result);
    }

    public function testToMarkdownHandlesNonDOMDocumentException(): void
    {
        // Create a mock logger
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())
            ->method('warning');

        // Create converter with logger
        $converter = new JiraHtmlConverter(null, $logger);

        // Use reflection to inject a mock converter that throws non-DOMDocument exception
        $reflection = new \ReflectionClass($converter);
        $markdownConverterProperty = $reflection->getProperty('markdownConverter');
        $markdownConverterProperty->setAccessible(true);

        // Create a mock that throws a different exception
        $mockConverter = $this->createMock(\League\HTMLToMarkdown\HtmlConverter::class);
        $mockConverter->method('convert')
            ->willThrowException(new \Exception('Some other error'));

        // Inject the mock
        $markdownConverterProperty->setValue($converter, $mockConverter);

        // Test that exception is caught and original content is returned
        $content = '<p>Test content</p>';
        $result = $converter->toMarkdown($content);

        // Should return original content when exception occurs
        $this->assertSame($content, $result);
    }

    public function testConstructorWithLogger(): void
    {
        $logger = $this->createMock(Logger::class);
        $converter = new JiraHtmlConverter(null, $logger);

        $this->assertInstanceOf(JiraHtmlConverter::class, $converter);
    }

    public function testToMarkdownHandlesDOMDocumentExceptionWithoutLogger(): void
    {
        // Create converter without logger
        $converter = new JiraHtmlConverter();

        // Use reflection to inject a mock converter that throws DOMDocument exception
        $reflection = new \ReflectionClass($converter);
        $markdownConverterProperty = $reflection->getProperty('markdownConverter');
        $markdownConverterProperty->setAccessible(true);

        // Create a mock that throws DOMDocument-related exception
        $mockConverter = $this->createMock(\League\HTMLToMarkdown\HtmlConverter::class);
        $mockConverter->method('convert')
            ->willThrowException(new \Exception("Class 'DOMDocument' not found"));

        // Inject the mock
        $markdownConverterProperty->setValue($converter, $mockConverter);

        // Test that exception is caught and original content is returned (even without logger)
        $content = '<p>Test content</p>';
        $result = $converter->toMarkdown($content);

        // Should return original content when DOMDocument exception occurs
        $this->assertSame($content, $result);
    }

    public function testToMarkdownHandlesNonDOMDocumentExceptionWithoutLogger(): void
    {
        // Create converter without logger
        $converter = new JiraHtmlConverter();

        // Use reflection to inject a mock converter that throws non-DOMDocument exception
        $reflection = new \ReflectionClass($converter);
        $markdownConverterProperty = $reflection->getProperty('markdownConverter');
        $markdownConverterProperty->setAccessible(true);

        // Create a mock that throws a different exception
        $mockConverter = $this->createMock(\League\HTMLToMarkdown\HtmlConverter::class);
        $mockConverter->method('convert')
            ->willThrowException(new \Exception('Some other error'));

        // Inject the mock
        $markdownConverterProperty->setValue($converter, $mockConverter);

        // Test that exception is caught and original content is returned
        $content = '<p>Test content</p>';
        $result = $converter->toMarkdown($content);

        // Should return original content when exception occurs
        $this->assertSame($content, $result);
    }

    public function testToMarkdownHandlesDOMDocumentExceptionWithDifferentMessage(): void
    {
        // Create a mock logger
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                Logger::VERBOSITY_NORMAL,
                'PHP XML extension is not available. HTML to Markdown conversion disabled. Install php-xml extension.'
            );

        // Create converter with logger
        $converter = new JiraHtmlConverter(null, $logger);

        // Use reflection to inject a mock converter that throws DOMDocument exception with different message
        $reflection = new \ReflectionClass($converter);
        $markdownConverterProperty = $reflection->getProperty('markdownConverter');
        $markdownConverterProperty->setAccessible(true);

        // Create a mock that throws DOMDocument-related exception with different message format
        $mockConverter = $this->createMock(\League\HTMLToMarkdown\HtmlConverter::class);
        $mockConverter->method('convert')
            ->willThrowException(new \Exception('DOMDocument error occurred'));

        // Inject the mock
        $markdownConverterProperty->setValue($converter, $mockConverter);

        // Test that exception is caught and original content is returned
        $content = '<p>Test content</p>';
        $result = $converter->toMarkdown($content);

        // Should return original content when DOMDocument exception occurs
        $this->assertSame($content, $result);
    }
}
