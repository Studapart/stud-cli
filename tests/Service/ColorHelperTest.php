<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ColorHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ColorHelperTest extends TestCase
{
    private ColorHelper $colorHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $colors = [
            'success' => 'green',
            'error' => 'red',
            'jira_message' => 'blue',
            'table_header' => 'bright-blue',
        ];

        $this->colorHelper = new ColorHelper($colors);
    }

    public function testRegisterStylesRegistersAllColors(): void
    {
        $output = new BufferedOutput();
        $formatter = $output->getFormatter();

        $this->colorHelper->registerStyles($output);

        $this->assertTrue($formatter->hasStyle('success'));
        $this->assertTrue($formatter->hasStyle('error'));
        $this->assertTrue($formatter->hasStyle('jira_message'));
        $this->assertTrue($formatter->hasStyle('table_header'));
    }

    public function testRegisterStylesDoesNotOverrideExistingStyles(): void
    {
        $output = new BufferedOutput();
        $formatter = $output->getFormatter();

        // Register a custom style first
        $customStyle = new \Symfony\Component\Console\Formatter\OutputFormatterStyle('magenta');
        $formatter->setStyle('success', $customStyle);

        $this->colorHelper->registerStyles($output);

        // Should not override existing style
        $this->assertSame($customStyle, $formatter->getStyle('success'));
    }

    public function testFormatReturnsFormattedMessage(): void
    {
        $output = new BufferedOutput();
        $this->colorHelper->registerStyles($output);

        $formatted = $this->colorHelper->format('success', 'Test message');

        $this->assertSame('<success>Test message</>', $formatted);
    }

    public function testGetColorNameReturnsColorFromConfig(): void
    {
        $this->assertSame('green', $this->colorHelper->getColorName('success'));
        $this->assertSame('red', $this->colorHelper->getColorName('error'));
    }

    public function testGetColorNameReturnsDefaultWhenNotFound(): void
    {
        $this->assertSame('white', $this->colorHelper->getColorName('nonexistent'));
    }

    public function testConvertColorNameHandlesBrightVariants(): void
    {
        $colors = [
            'test' => 'bright-blue',
        ];
        $colorHelper = new ColorHelper($colors);

        $output = new BufferedOutput();
        $colorHelper->registerStyles($output);

        // Should register without error
        $this->assertTrue($output->getFormatter()->hasStyle('test'));
    }

    public function testRegisterStylesWithSymfonyStyle(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $formatter = $output->getFormatter();

        $this->colorHelper->registerStyles($io);

        $this->assertTrue($formatter->hasStyle('success'));
        $this->assertTrue($formatter->hasStyle('error'));
        $this->assertTrue($formatter->hasStyle('jira_message'));
        $this->assertTrue($formatter->hasStyle('table_header'));
    }

    public function testConvertColorNameHandlesDarkVariants(): void
    {
        $colors = [
            'test' => 'darkblue',
        ];
        $colorHelper = new ColorHelper($colors);

        $output = new BufferedOutput();
        $colorHelper->registerStyles($output);

        // Should register without error
        $this->assertTrue($output->getFormatter()->hasStyle('test'));
    }
}
