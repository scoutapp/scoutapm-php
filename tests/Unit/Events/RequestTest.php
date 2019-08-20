<?php

declare(strict_types=1);

namespace Scoutapm\UnitTests\Events;

use PHPUnit\Framework\TestCase;
use Scoutapm\Agent;
use Scoutapm\Events\Request;
use function next;
use function reset;

/**
 * Test Case for @see \Scoutapm\Events\Request
 */
final class RequestTest extends TestCase
{
    public function testCanBeInitialized() : void
    {
        $request = new Request(new Agent(), '');
        $this->assertNotNull($request);
    }

    public function testCanBeStopped() : void
    {
        $request = new Request(new Agent(), '');
        $request->stop();
        $this->assertNotNull($request);
    }

    public function testJsonSerializes() : void
    {
        // Make a request with some interesting content.
        $request = new Request(new Agent(), '');
        $request->tag('t', 'v');
        $span = $request->startSpan('foo');
        $span->tag('spantag', 'spanvalue');
        $request->stopSpan();
        $request->stop();

        $serialized = $request->jsonSerialize();
        $this->assertIsArray($serialized);
        $this->assertArrayHasKey('StartRequest', reset($serialized));
        $this->assertArrayHasKey('TagRequest', next($serialized));

        $this->assertArrayHasKey('StartSpan', next($serialized));
        $this->assertArrayHasKey('TagSpan', next($serialized));
        $this->assertArrayHasKey('StopSpan', next($serialized));

        $this->assertArrayHasKey('FinishRequest', next($serialized));
    }
}
