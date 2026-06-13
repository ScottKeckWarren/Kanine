<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Console\Application;
use Symfony\Component\Console\Application as SymfonyApplication;

final class ApplicationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Instantiation
    // -------------------------------------------------------------------------

    public function testApplicationIsInstantiableWithLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertInstanceOf(Application::class, $app);
    }

    public function testApplicationExtendsSymfonyConsoleApplication(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertInstanceOf(SymfonyApplication::class, $app);
    }

    // -------------------------------------------------------------------------
    // Application identity
    // -------------------------------------------------------------------------

    public function testApplicationNameIsKanine(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertSame('kanine', $app->getName());
    }

    // -------------------------------------------------------------------------
    // Logger is stored
    // -------------------------------------------------------------------------

    public function testGetLoggerReturnsTheInjectedLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertSame($logger, $app->getLogger());
    }

    // -------------------------------------------------------------------------
    // Default commands
    // -------------------------------------------------------------------------

    public function testApplicationRegistersDefaultSymfonyCommands(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertTrue($app->has('help'));
        $this->assertTrue($app->has('list'));
    }

    public function testApplicationAutoExitCanBeDisabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);
        $app->setAutoExit(false);

        $this->assertFalse($app->isAutoExitEnabled());
    }
}
