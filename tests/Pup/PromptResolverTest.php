<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Pup;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Pup\PromptResolver;
use ScottKeckWarren\Kanine\ValueObject\String\Prompt;

final class PromptResolverTest extends TestCase
{
    private string $tmpDir;

    // -------------------------------------------------------------------------
    // testResolvesFirstMatchingLabel
    // -------------------------------------------------------------------------

    public function testResolvesFirstMatchingLabel(): void
    {
        $this->createPromptFile('bug', 'Fix the bug carefully.');

        $resolver = $this->makeResolver();
        $prompt   = $resolver->resolve(['bug']);

        $this->assertInstanceOf(Prompt::class, $prompt);
        $this->assertSame('Fix the bug carefully.', $prompt->value);
    }

    // -------------------------------------------------------------------------
    // testSkipsNonMatchingLabel
    // -------------------------------------------------------------------------

    public function testSkipsNonMatchingLabel(): void
    {
        $this->createPromptFile('feature', 'Implement the feature.');

        $resolver = $this->makeResolver();
        $prompt   = $resolver->resolve(['no-match', 'feature']);

        $this->assertSame('Implement the feature.', $prompt->value);
    }

    // -------------------------------------------------------------------------
    // testReturnsDefaultWhenNoLabelsMatch
    // -------------------------------------------------------------------------

    public function testReturnsDefaultWhenNoLabelsMatch(): void
    {
        $resolver = $this->makeResolver();
        $prompt   = $resolver->resolve(['ghost', 'missing']);

        $this->assertSame(PromptResolver::DEFAULT_PROMPT, $prompt->value);
    }

    // -------------------------------------------------------------------------
    // testSkipsLabelWithForwardSlash
    // -------------------------------------------------------------------------

    public function testSkipsLabelWithForwardSlash(): void
    {
        $this->createPromptFile('safe', 'Safe prompt content.');

        $resolver = $this->makeResolver();
        $prompt   = $resolver->resolve(['foo/bar', 'safe']);

        $this->assertSame('Safe prompt content.', $prompt->value);
    }

    // -------------------------------------------------------------------------
    // testSkipsLabelWithNullByte
    // -------------------------------------------------------------------------

    public function testSkipsLabelWithNullByte(): void
    {
        $this->createPromptFile('clean', 'Clean prompt.');

        $resolver = $this->makeResolver();
        $prompt   = $resolver->resolve(["label\0evil", 'clean']);

        $this->assertSame('Clean prompt.', $prompt->value);
    }

    // -------------------------------------------------------------------------
    // testSkipsPathTraversalLabel
    // -------------------------------------------------------------------------

    public function testSkipsPathTraversalLabel(): void
    {
        $resolver = $this->makeResolver();
        $prompt   = $resolver->resolve(['../../etc']);

        $this->assertSame(PromptResolver::DEFAULT_PROMPT, $prompt->value);
    }

    // -------------------------------------------------------------------------
    // testLogsResolvedFilePath
    // -------------------------------------------------------------------------

    public function testLogsResolvedFilePath(): void
    {
        $this->createPromptFile('enhancement', 'Enhance things.');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('.kanine/labels/enhancement/prompt.md'));

        $resolver = new PromptResolver(basePath: $this->tmpDir, logger: $logger);
        $resolver->resolve(['enhancement']);
    }

    // -------------------------------------------------------------------------
    // testLogsDefaultSelection
    // -------------------------------------------------------------------------

    public function testLogsDefaultSelection(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('default'));

        $resolver = new PromptResolver(basePath: $this->tmpDir, logger: $logger);
        $resolver->resolve(['no-such-label']);
    }

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prompt_resolver_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeResolver(?LoggerInterface $logger = null): PromptResolver
    {
        return new PromptResolver(
            basePath: $this->tmpDir,
            logger: $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function createPromptFile(string $label, string $content): void
    {
        $dir = $this->tmpDir . '/.kanine/labels/' . $label;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/prompt.md', $content);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;

            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
