<?php

declare(strict_types=1);

namespace Scoutapm\UnitTests\Laravel\Console;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scoutapm\Laravel\Console\ConsoleListener;
use Scoutapm\ScoutApmAgent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @covers \Scoutapm\Laravel\Console\ConsoleListener */
final class ConsoleListenerTest extends TestCase
{
    /** @var ScoutApmAgent&MockObject */
    private $agent;
    /** @var ConsoleListener */
    private $consoleListener;

    public function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createMock(ScoutApmAgent::class);

        $this->consoleListener = new ConsoleListener($this->agent, ['--foo', 'bar']);
    }

    public function testStartCommandWillResetRequestTagWithArgsAndStartSpan(): void
    {
        $this->agent
            ->expects(self::once())
            ->method('startNewRequest');
        $this->agent
            ->expects(self::once())
            ->method('addContext')
            ->with('args', '--foo bar');
        $this->agent
            ->expects(self::once())
            ->method('startSpan')
            ->with('Job/artisan/foo:command');

        $event = new CommandStarting(
            'foo:command',
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class)
        );

        $this->consoleListener->startSpanForCommand($event);
    }

    public function testStartCommandDoesNothingWhenCommandIsNull(): void
    {
        $this->agent
            ->expects(self::never())
            ->method('startNewRequest');
        $this->agent
            ->expects(self::never())
            ->method('addContext');
        $this->agent
            ->expects(self::never())
            ->method('startSpan');

        $event = new CommandStarting(
            null,
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class)
        );

        $this->consoleListener->startSpanForCommand($event);
    }

    public function testStopCommandWillStopConnectAndSend(): void
    {
        $this->agent
            ->expects(self::once())
            ->method('stopSpan');
        $this->agent
            ->expects(self::once())
            ->method('connect');
        $this->agent
            ->expects(self::once())
            ->method('send');

        $event = new CommandFinished(
            'foo:command',
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class),
            0
        );

        $this->consoleListener->stopSpanForCommand($event);
    }

    public function testStopCommandDoesNothingWhenCommandIsNull(): void
    {
        $this->agent
            ->expects(self::never())
            ->method('stopSpan');
        $this->agent
            ->expects(self::never())
            ->method('connect');
        $this->agent
            ->expects(self::never())
            ->method('send');

        $event = new CommandFinished(
            null,
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class),
            0
        );

        $this->consoleListener->stopSpanForCommand($event);
    }
}
