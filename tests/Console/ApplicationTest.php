<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ScottKeckWarren\Kanine\Console\Application;
use Symfony\Component\Console\Application as SymfonyApplication;

final class ApplicationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Instantiation
    // -------------------------------------------------------------------------

    public function test_application_is_instantiable_with_logger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertInstanceOf(Application::class, $app);
    }

    public function test_application_extends_symfony_console_application(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertInstanceOf(SymfonyApplication::class, $app);
    }

    // -------------------------------------------------------------------------
    // Application identity
    // -------------------------------------------------------------------------

    public function test_application_name_is_kanine(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertSame('kanine', $app->getName());
    }

    // -------------------------------------------------------------------------
    // Logger is stored
    // -------------------------------------------------------------------------

    public function test_get_logger_returns_the_injected_logger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertSame($logger, $app->getLogger());
    }

    // -------------------------------------------------------------------------
    // Default commands
    // -------------------------------------------------------------------------

    public function test_application_registers_default_symfony_commands(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);

        $this->assertTrue($app->has('help'));
        $this->assertTrue($app->has('list'));
    }

    public function test_application_auto_exit_can_be_disabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $app    = new Application($logger);
        $app->setAutoExit(false);

        $this->assertFalse($app->isAutoExitEnabled());
    }
}
