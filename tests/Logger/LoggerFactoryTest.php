<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Logger\LoggerFactory;

final class LoggerFactoryTest extends TestCase
{
    private string $tempDir;

    // -------------------------------------------------------------------------
    // Instantiation
    // -------------------------------------------------------------------------

    public function testLoggerFactoryIsInstantiableWithoutArguments(): void
    {
        $factory = new LoggerFactory();

        $this->assertInstanceOf(LoggerFactory::class, $factory);
    }

    // -------------------------------------------------------------------------
    // create() returns a PSR-3 LoggerInterface
    // -------------------------------------------------------------------------

    public function testCreateReturnsPsr3Logger(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testCreateReturnsMonologLoggerInstance(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        $this->assertInstanceOf(Logger::class, $logger);
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public function testCreateAttachesStreamHandler(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        $hasStream = false;
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler) {
                $hasStream = true;
                break;
            }
        }

        $this->assertTrue($hasStream, 'Logger should have a StreamHandler attached');
    }

    public function testCreateAttachesRotatingFileHandler(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        $hasRotating = false;
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof RotatingFileHandler) {
                $hasRotating = true;
                break;
            }
        }

        $this->assertTrue($hasRotating, 'Logger should have a RotatingFileHandler attached');
    }

    // -------------------------------------------------------------------------
    // Log file is created when a message is written
    // -------------------------------------------------------------------------

    public function testWritingLogMessageCreatesLogFile(): void
    {
        $logFile = $this->tempDir . '/kanine.log';
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $logFile);

        $logger->debug('Test log entry');

        // RotatingFileHandler appends the date to the filename
        $files = glob($this->tempDir . '/kanine*.log');
        $this->assertNotEmpty($files, 'Log file should exist after writing a message');
    }

    // -------------------------------------------------------------------------
    // Log output includes timestamp and level
    // -------------------------------------------------------------------------

    public function testLogLineIncludesLevel(): void
    {
        $logFile = $this->tempDir . '/kanine.log';
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $logFile);

        $logger->info('Check level in output');

        $files = glob($this->tempDir . '/kanine*.log');
        $this->assertNotEmpty($files);

        $contents = (string) file_get_contents((string) $files[0]);
        $this->assertStringContainsString('INFO', $contents);
    }

    public function testLogLineIncludesTimestamp(): void
    {
        $logFile = $this->tempDir . '/kanine.log';
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $logFile);

        $logger->info('Check timestamp in output');

        $files = glob($this->tempDir . '/kanine*.log');
        $this->assertNotEmpty($files);

        $contents = (string) file_get_contents((string) $files[0]);
        // Monolog default format includes a date like [2024-01-01T...]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2}/', $contents);
    }

    // -------------------------------------------------------------------------
    // Channel name
    // -------------------------------------------------------------------------

    public function testCreateUsesKanineAsChannelName(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        $this->assertSame('kanine', $logger->getName());
    }

    public function testCreateUsesCustomChannelNameWhenProvided(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(
            logFile: $this->tempDir . '/kanine.log',
            channel: 'my-channel',
        );

        $this->assertSame('my-channel', $logger->getName());
    }

    // -------------------------------------------------------------------------
    // Handler configuration
    // -------------------------------------------------------------------------

    public function testStreamHandlerLevelIsDebug(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler && !$handler instanceof RotatingFileHandler) {
                $this->assertSame(\Monolog\Level::Debug, $handler->getLevel());
                return;
            }
        }

        $this->fail('StreamHandler not found');
    }

    public function testRotatingFileHandlerLevelIsDebug(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof RotatingFileHandler) {
                $this->assertSame(\Monolog\Level::Debug, $handler->getLevel());
                return;
            }
        }

        $this->fail('RotatingFileHandler not found');
    }

    public function testRotatingFileHandlerMaxFilesIsSeven(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof RotatingFileHandler) {
                $ref = new \ReflectionProperty(RotatingFileHandler::class, 'maxFiles');
                $this->assertSame(7, $ref->getValue($handler));
                return;
            }
        }

        $this->fail('RotatingFileHandler not found');
    }

    public function testStreamHandlerTargetIsStderr(): void
    {
        $factory = new LoggerFactory();
        $logger  = $factory->create(logFile: $this->tempDir . '/kanine.log');

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler && !$handler instanceof RotatingFileHandler) {
                $ref = new \ReflectionProperty(StreamHandler::class, 'url');
                $this->assertSame('php://stderr', $ref->getValue($handler));
                return;
            }
        }

        $this->fail('StreamHandler not found');
    }

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kanine-logger-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
