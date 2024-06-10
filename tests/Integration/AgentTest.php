<?php

declare(strict_types=1);

namespace Scoutapm\IntegrationTests;

use Exception;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scoutapm\Agent;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;
use Scoutapm\Connector\ConnectionAddress;
use Scoutapm\Connector\SocketConnector;
use Scoutapm\Events\Span\SpanReference;
use Scoutapm\Extension\PotentiallyAvailableExtensionCapabilities;
use Scoutapm\Helper\Platform;
use Scoutapm\UnitTests\TestLogger;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function assert;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function fopen;
use function function_exists;
use function getenv;
use function gethostname;
use function in_array;
use function meminfo_dump;
use function memory_get_usage;
use function next;
use function reset;
use function scoutapm_list_instrumented_functions;
use function shell_exec;
use function sleep;
use function sprintf;
use function str_repeat;
use function stream_context_create;
use function trim;
use function uniqid;
use function unlink;

use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_POST;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_URL;

/**
 * @psalm-import-type UnserializedCapturedMessagesList from MessageCapturingConnectorDelegator
 * @coversNothing
 *
 * Running these in separate process is required due to the way PHAR caches data internally
 * @runTestsInSeparateProcesses
 */
final class AgentTest extends TestCase
{
    private const APPLICATION_NAME = 'Agent Integration Test';

    /** @var TestLogger */
    private $logger;
    /** @var MessageCapturingConnectorDelegator */
    private $connector;
    /** @var Agent */
    private $agent;
    /** @var string */
    private $scoutApmKey;

    public function setUp(): void
    {
        parent::setUp();

        // Note, env var name is intentionally inconsistent (i.e. not `SCOUT_KEY`) as we only want to affect this test
        $scoutApmKey = (string) getenv('SCOUT_APM_KEY');

        if ($scoutApmKey === '') {
            self::markTestSkipped('Set the environment variable SCOUT_APM_KEY to enable this test.');
        }

        $this->scoutApmKey = $scoutApmKey;
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->cleanUpTestAssets();
    }

    private function cleanUpTestAssets(): void
    {
        // Windows integration test leaves a single persistent instance of core-agent running, so don't shut it down
        if (Platform::isWindows()) {
            return;
        }

        /** @psalm-suppress ForbiddenCode */
        shell_exec('killall -q core-agent || true');
        /** @psalm-suppress ForbiddenCode */
        shell_exec('rm -Rf /tmp/scout_apm_core');
    }

    private function setUpWithConfiguration(Config $config): void
    {
        $config->set(ConfigKey::APPLICATION_KEY, $this->scoutApmKey);

        $this->logger = new TestLogger();

        $this->connector = new MessageCapturingConnectorDelegator(new SocketConnector(
            ConnectionAddress::fromConfig($config),
            true,
            $this->logger
        ));

        $_SERVER['REQUEST_URI'] = '/fake-path';

        $this->agent = Agent::fromConfig($config, $this->logger, null, $this->connector);

        if ($config->get(ConfigKey::MONITORING_ENABLED)) {
            $retryCount = 0;
            while ($retryCount < 5 && ! $this->connector->connected()) {
                $this->agent->connect();
                sleep(1);
                $retryCount++;
            }

            if (! $this->connector->connected()) {
                self::fail('Could not connect to core agent in test harness. ' . $this->formatCapturedLogMessages());
            }
        }

        (new PotentiallyAvailableExtensionCapabilities())->clearRecordedCalls();
    }

    private function formatCapturedLogMessages(): string
    {
        $return = "Log messages:\n";

        foreach ($this->logger->records as $logMessage) {
            $return .= sprintf("[%s] %s\n", $logMessage['level'], $logMessage['message']);
        }

        return $return;
    }

    /**
     * We can't leave exceptions "uncaught" in PHPUnit, since the runner will always capture any exceptions this test
     * might raise. Therefore, the actual test script has been written as an external PHP file which is executed, and
     * we analyse the logs generated to inspect that the exception was sent to Scout's error reporting system.
     */
    public function testUncaughtErrorsAreCapturedAndSentToScout(): void
    {
        $phpBinary = (new PhpExecutableFinder())->find();

        $process = new Process([
            $phpBinary,
            __DIR__ . '/../isolated-error-capture-test.php',
        ]);
        $process->run();

        $logFileGeneratedByTestScript = trim($process->getOutput());

        $logContents = file_get_contents($logFileGeneratedByTestScript);

        if (! $process->isSuccessful()) {
            self::fail(sprintf(
                "Failed. %s\n\nLog file: %s",
                $logFileGeneratedByTestScript,
                $logContents
            ));
        }

        self::assertStringContainsString('Sent 1 error event to Scout Error Reporting', $logContents);

        if (! file_exists($logFileGeneratedByTestScript)) {
            return;
        }

        unlink($logFileGeneratedByTestScript);
    }

    /**
     * We can't leave exceptions "uncaught" in PHPUnit, since the runner will always capture any exceptions this test
     * might raise. Therefore, the actual test script has been written as an external PHP file which is executed, and
     * we analyse the logs generated to inspect that the exception was sent to Scout's error reporting system.
     */
    public function testRecordedThrowablesAreSentToScout(): void
    {
        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => true,
            ConfigKey::ERRORS_ENABLED => true,
        ]));

        $this->agent->recordThrowable(new RuntimeException('something went wrong'));
        $this->agent->send();

        self::assertTrue(
            $this->logger->hasDebugThatContains('"errors_enabled":true'),
            'Debug did not have errors_enabled flag set: ' . $this->formatCapturedLogMessages()
        );
        self::assertTrue(
            $this->logger->hasDebugThatContains('Sent 1 error event to Scout Error Reporting'),
            'Debug did not indicate that error event was sent successfully: ' . $this->formatCapturedLogMessages()
        );
    }

    public function testForMemoryLeaksWhenHandlingJobQueues(): void
    {
        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => true,
        ]));

        $tagSize = 500000;

        $startingMemory = memory_get_usage();
        for ($i = 1; $i <= 500; $i++) {
            $this->agent->startNewRequest();
            $span = $this->agent->startSpan(sprintf(
                '%s/%s%d',
                SpanReference::INSTRUMENT_JOB,
                'Test Job #',
                $i
            ));

            assert($span !== null);

            $span->tag('something', str_repeat('a', $tagSize));

            $this->agent->stopSpan();

            $this->agent->connect();
            $this->agent->send();
        }

        // Logging can affect memory usage since the TestLogger persists the messages in memory
        $this->logger->records        = [];
        $this->logger->recordsByLevel = [];

        // MessageCapturingConnectorDelegator also persists the sent messages in memory for tests, so free that up
        $this->connector->sentMessages = [];

        /**
         * Install https://github.com/BitOne/php-meminfo to get memory analysis here to identify where memory is
         * allocated, then use `bin/analyzer summary /tmp/my_dump_file.json` to show table.
         */
        if (function_exists('meminfo_dump')) {
            /** @psalm-suppress TooManyArguments */
            meminfo_dump(fopen('/tmp/my_dump_file.json', 'w'));
        }

        // It is worth noting that other things are collecting information here too (e.g. xdebug, phpunit), so we can
        // expect memory to increase regardless.
        self::assertLessThan($tagSize * 2, memory_get_usage() - $startingMemory);
    }

    /** @psalm-return iterable<string,list<Config>> */
    public function endToEndConfigurationProvider(): iterable
    {
        yield 'defaultBasicConfiguration' => [
            Config::fromArray([
                ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
                ConfigKey::MONITORING_ENABLED => true,
            ]),
        ];

        if (Platform::isWindows()) {
            // Sockets can only be used on Linux
            return;
        }

        yield 'unixSocketConfiguration' => [
            Config::fromArray([
                ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
                ConfigKey::MONITORING_ENABLED => true,
                ConfigKey::CORE_AGENT_SOCKET_PATH => '/tmp/scout_apm_core/core-agent.sock',
            ]),
        ];
    }

    public function testResponseIsReadCorrectlyWhenResponseSizeExceedsBufferLimit(): void
    {
        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => true,
        ]));

        for ($i = 0; $i < 2; $i++) {
            for ($j = 0; $j < 500; $j++) {
                $this->agent->instrument('Test', 'qux', static function (): void {
                });
            }

            self::assertTrue($this->agent->send(), 'Failed to send messages. ' . $this->formatCapturedLogMessages());
            self::assertFalse($this->logger->hasNoticeThatContains('exceeded our limit for reading'), 'Response read limit reached. ' . $this->formatCapturedLogMessages());
        }
    }

    /**
     * @throws Exception
     *
     * @dataProvider endToEndConfigurationProvider
     */
    public function testLoggingIsSentUsingConfiguration(Config $config): void
    {
        $this->setUpWithConfiguration($config);

        $this->agent->webTransaction('Yay', function (): void {
            file_get_contents(__FILE__);
            $this->agent->instrument('Test', 'foo', function (): void {
                file_get_contents(__FILE__);
                sleep(1);
                $this->agent->instrument('Test', 'bar', static function (): void {
                    file_get_contents(__FILE__);
                    sleep(1);
                });
            });
            file_get_contents(__FILE__);
            $this->agent->tagRequest('testtag', '1.23');
            $this->agent->instrument('DB', 'test', static function (): void {
            });
        });
        $this->agent->instrument('Test', 'qux', static function (): void {
        });

        self::assertTrue($this->agent->send(), 'Failed to send messages. ' . $this->formatCapturedLogMessages());

        $unserialized = $this->connector->sentMessages;

        TestHelper::assertUnserializedCommandContainsPayload(
            'Register',
            [
                'app' => 'Agent Integration Test',
                'key' => $this->scoutApmKey,
                'language' => 'php',
                'api_version' => '1.0',
            ],
            reset($unserialized),
            null
        );
        TestHelper::assertUnserializedCommandContainsPayload(
            'ApplicationEvent',
            [
                'event_type' => 'scout.metadata',
                'source' => 'php',
                'event_value' => static function (array $data): bool {
                    self::assertSame('php', $data['language']);
                    self::assertSame(gethostname(), $data['hostname']);

                    return true;
                },
            ],
            next($unserialized),
            null
        );

        $batchCommand = next($unserialized);
        TestHelper::assertUnserializedCommandContainsPayload(
            'BatchCommand',
            [
                'commands' =>
                    /** @psalm-param UnserializedCapturedMessagesList $commands */
                    static function (array $commands): bool {
                        $requestId = TestHelper::assertUnserializedCommandContainsPayload(
                            'StartRequest',
                            [
                                'timestamp' => [TestHelper::class, 'assertValidTimestamp'],
                            ],
                            reset($commands),
                            'request_id'
                        );

                        $controllerSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Controller/Yay'], next($commands), 'span_id');

                        if (TestHelper::scoutApmExtensionAvailable()) {
                            $fileGetContentsSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'file_get_contents', 'parent_id' => $controllerSpanId], next($commands), 'span_id');
                            TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['span_id' => $fileGetContentsSpanId, 'tag' => 'args', 'value' => ['url' => __FILE__, 'method' => 'GET']], next($commands), null);
                            TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $fileGetContentsSpanId], next($commands), null);
                        }

                        $fooSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Test/foo'], next($commands), 'span_id');

                        if (TestHelper::scoutApmExtensionAvailable()) {
                            $fileGetContentsSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'file_get_contents', 'parent_id' => $fooSpanId], next($commands), 'span_id');
                            TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['span_id' => $fileGetContentsSpanId, 'tag' => 'args', 'value' => ['url' => __FILE__, 'method' => 'GET']], next($commands), null);
                            TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $fileGetContentsSpanId], next($commands), null);
                        }

                        $barSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Test/bar'], next($commands), 'span_id');

                        if (TestHelper::scoutApmExtensionAvailable()) {
                            $fileGetContentsSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'file_get_contents', 'parent_id' => $barSpanId], next($commands), 'span_id');
                            TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['span_id' => $fileGetContentsSpanId, 'tag' => 'args', 'value' => ['url' => __FILE__, 'method' => 'GET']], next($commands), null);
                            TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $fileGetContentsSpanId], next($commands), null);
                        }

                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'stack', 'span_id' => $barSpanId], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $barSpanId], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'stack', 'span_id' => $fooSpanId], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $fooSpanId], next($commands), null);

                        if (TestHelper::scoutApmExtensionAvailable()) {
                            $fileGetContentsSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'file_get_contents', 'parent_id' => $controllerSpanId], next($commands), 'span_id');
                            TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['span_id' => $fileGetContentsSpanId, 'tag' => 'args', 'value' => ['url' => __FILE__, 'method' => 'GET']], next($commands), null);
                            TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $fileGetContentsSpanId], next($commands), null);
                        }

                        $dbSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'DB/test'], next($commands), 'span_id');
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $dbSpanId], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $controllerSpanId], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'testtag', 'value' => '1.23', 'request_id' => $requestId], next($commands), null);

                        $quxSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Test/qux'], next($commands), 'span_id');
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $quxSpanId], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'memory_delta', 'value' => [TestHelper::class, 'assertValidMemoryUsage'], 'request_id' => $requestId], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'path', 'value' => '/fake-path', 'request_id' => $requestId], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload(
                            'FinishRequest',
                            [
                                'request_id' => $requestId,
                                'timestamp' => [TestHelper::class, 'assertValidTimestamp'],
                            ],
                            next($commands),
                            null
                        );

                        return true;
                    },
            ],
            $batchCommand,
            null
        );
    }

    /**
     * Run Mongo with:
     *
     * ```
     * docker run --rm --name some-mongo -p 27017:27017 -d mongo:latest
     * ```
     *
     * @group mongo
     */
    public function testMongoDbDoesNotStartSpansWhenMonitoringIsDisabled(): void
    {
        if (! extension_loaded('mongodb')) {
            self::markTestSkipped('MongoDB extension required for this test - mongodb is not loaded');
        }

        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => false,
        ]));

        $mongo = new Manager('mongodb://localhost:27017');

        try {
            $mongo->startSession();
        } catch (ConnectionTimeoutException $timeoutException) {
            self::markTestSkipped('Could not connect to mongodb server, is it running?');
        }

        $db         = 'scout-apm-test-db';
        $collection = uniqid('scout-apm-test-', true);

        $mongo->executeCommand($db, new Command(['create' => $collection]));

        $request = $this->agent->getRequest();
        self::assertNotNull($request);
        self::assertEmpty($request->getEvents());
    }

    /**
     * Run Mongo with:
     *
     * ```
     * docker run --rm --name some-mongo -p 27017:27017 -d mongo:latest
     * ```
     *
     * @group mongo
     */
    public function testMongoDbInstrumentation(): void
    {
        if (! extension_loaded('mongodb')) {
            self::markTestSkipped('MongoDB extension required for this test - mongodb is not loaded');
        }

        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => true,
        ]));

        $mongo = new Manager('mongodb://localhost:27017');

        try {
            $mongo->startSession();
        } catch (ConnectionTimeoutException $timeoutException) {
            self::markTestSkipped('Could not connect to mongodb server, is it running?');
        }

        $db         = 'scout-apm-test-db';
        $collection = uniqid('scout-apm-test-', true);
        $helloValue = uniqid('helloValue', true);

        $mongo->executeCommand($db, new Command(['create' => $collection]));

        $write = new BulkWrite();
        $write->insert(['_id' => 1, 'hello' => $helloValue]);
        $mongo->executeBulkWrite($db . '.' . $collection, $write);

        $cursor = $mongo->executeQuery($db . '.' . $collection, new Query(['_id' => 1]));
        $cursor->rewind();
        /** @psalm-suppress PossiblyInvalidPropertyFetch This is the expected API of MongoDB ext */
        self::assertSame($helloValue, $cursor->current()->hello);

        self::assertTrue($this->agent->send(), 'Failed to send messages. ' . $this->formatCapturedLogMessages());

        $unserialized = $this->connector->sentMessages;
        reset($unserialized); // Skip Register event
        next($unserialized); // Skip Metadata event

        TestHelper::assertUnserializedCommandContainsPayload(
            'BatchCommand',
            [
                'commands' =>
                    /** @psalm-param UnserializedCapturedMessagesList $commands */
                    static function (array $commands) use ($db): bool {
                        TestHelper::assertUnserializedCommandContainsPayload('StartRequest', [], reset($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Mongo/Query/create'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'db', 'value' => $db], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'operationId'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'requestId'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', [], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Mongo/Query/insert'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'db', 'value' => $db], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'operationId'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'requestId'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', [], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Mongo/Query/find'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'db', 'value' => $db], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'operationId'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'requestId'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', [], next($commands), null);

                        return true;
                    },
            ],
            next($unserialized),
            null
        );
    }

    public function testLeafSpansDoNotHaveChildren(): void
    {
        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => true,
        ]));

        $leaf = $this->agent->startSpan('LeafOperation', null, true);
        assert($leaf !== null);
        $this->agent->startSpan('ShouldNotBeSerialized');
        $this->agent->stopSpan();
        $leaf->tag('LeafShouldBeTagged', 'something');
        $this->agent->stopSpan();
        $this->agent->startSpan('AnotherOperation');
        $this->agent->stopSpan();
        self::assertTrue($this->agent->send(), 'Failed to send messages. ' . $this->formatCapturedLogMessages());

        $unserialized = $this->connector->sentMessages;
        reset($unserialized); // Skip Register event
        next($unserialized); // Skip Metadata event

        TestHelper::assertUnserializedCommandContainsPayload(
            'BatchCommand',
            [
                'commands' =>
                /** @psalm-param UnserializedCapturedMessagesList $commands */
                    static function (array $commands): bool {
                        TestHelper::assertUnserializedCommandContainsPayload('StartRequest', [], reset($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'LeafOperation'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['tag' => 'LeafShouldBeTagged'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', [], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'AnotherOperation'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', [], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'memory_delta'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'path'], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('FinishRequest', [], next($commands), null);

                        return true;
                    },
            ],
            next($unserialized),
            null
        );
    }

    /** @noinspection PhpExpressionResultUnusedInspection */
    public function testHttpSpansArePromoted(): void
    {
        if (! TestHelper::scoutApmExtensionAvailable()) {
            self::markTestSkipped('scoutapm extension must be enabled for HTTP spans');
        }

        if (! extension_loaded('curl')) {
            self::markTestSkipped('curl extension must be enabled for HTTP spans');
        }

        if (! in_array('curl_exec', scoutapm_list_instrumented_functions(), true)) {
            self::markTestSkipped('scoutapm extension was not compiled with curl support');
        }

        $this->setUpWithConfiguration(Config::fromArray([
            ConfigKey::APPLICATION_NAME => self::APPLICATION_NAME,
            ConfigKey::MONITORING_ENABLED => true,
        ]));

        $httpUrl  = 'http://scoutapm.com/robots.txt';
        $httpsUrl = 'https://scoutapm.com/robots.txt';

        $this->agent->webTransaction(
            'TestingHttpSpans',
            static function () use ($httpUrl, $httpsUrl): void {
                file_get_contents($httpUrl);

                // 405 Method Not Allowed is expected, and emitted as a warning, so ignore for this test
                @file_get_contents($httpsUrl, false, stream_context_create([
                    'http' => ['method' => 'POST'],
                ]));

                $httpCurl = curl_init();
                curl_setopt($httpCurl, CURLOPT_URL, $httpUrl);
                curl_setopt($httpCurl, CURLOPT_RETURNTRANSFER, true);
                curl_exec($httpCurl);

                $httpsPostCurl = curl_init();
                curl_setopt($httpsPostCurl, CURLOPT_URL, $httpsUrl);
                curl_setopt($httpsPostCurl, CURLOPT_POST, 1);
                curl_setopt($httpsPostCurl, CURLOPT_RETURNTRANSFER, true);
                curl_exec($httpsPostCurl);

                $httpsPutCurl = curl_init();
                curl_setopt($httpsPutCurl, CURLOPT_URL, $httpsUrl);
                curl_setopt($httpsPutCurl, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($httpsPutCurl, CURLOPT_RETURNTRANSFER, true);
                curl_exec($httpsPutCurl);
            }
        );

        self::assertTrue($this->agent->send(), 'Failed to send messages. ' . $this->formatCapturedLogMessages());

        $unserialized = $this->connector->sentMessages;
        reset($unserialized); // Skip Register event
        next($unserialized); // Skip Metadata event

        $assertSpanContainingHttpSpan =
            /** @psalm-param list<array<string, array<string, mixed>>> $commands */
            static function (&$commands, string $outerOperation, string $method, string $url): void {
                $fileGetContentsSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => $outerOperation], next($commands), 'span_id');

                $httpSpanId = TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'HTTP/' . $method, 'parent_id' => $fileGetContentsSpanId], next($commands), 'span_id');
                TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['span_id' => $httpSpanId, 'tag' => 'uri', 'value' => $url], next($commands), null);
                TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $httpSpanId], TestHelper::skipBacktraceTagIfNext($commands), null);

                TestHelper::assertUnserializedCommandContainsPayload('TagSpan', ['span_id' => $fileGetContentsSpanId, 'tag' => 'args', 'value' => ['url' => $url, 'method' => $method]], next($commands), null);
                TestHelper::assertUnserializedCommandContainsPayload('StopSpan', ['span_id' => $fileGetContentsSpanId], TestHelper::skipBacktraceTagIfNext($commands), null);
            };

        TestHelper::assertUnserializedCommandContainsPayload(
            'BatchCommand',
            [
                'commands' =>
                    /** @psalm-param UnserializedCapturedMessagesList $commands */
                    static function (array $commands) use ($assertSpanContainingHttpSpan, $httpUrl, $httpsUrl): bool {
                        TestHelper::assertUnserializedCommandContainsPayload('StartRequest', [], reset($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('StartSpan', ['operation' => 'Controller/TestingHttpSpans'], next($commands), null);

                        $assertSpanContainingHttpSpan($commands, 'file_get_contents', 'GET', $httpUrl);
                        $assertSpanContainingHttpSpan($commands, 'file_get_contents', 'POST', $httpsUrl);
                        $assertSpanContainingHttpSpan($commands, 'curl_exec', 'GET', $httpUrl);
                        $assertSpanContainingHttpSpan($commands, 'curl_exec', 'POST', $httpsUrl);
                        $assertSpanContainingHttpSpan($commands, 'curl_exec', 'PUT', $httpsUrl);

                        TestHelper::assertUnserializedCommandContainsPayload('StopSpan', [], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'memory_delta'], next($commands), null);
                        TestHelper::assertUnserializedCommandContainsPayload('TagRequest', ['tag' => 'path'], next($commands), null);

                        TestHelper::assertUnserializedCommandContainsPayload('FinishRequest', [], next($commands), null);

                        return true;
                    },
            ],
            next($unserialized),
            null
        );
    }
}
