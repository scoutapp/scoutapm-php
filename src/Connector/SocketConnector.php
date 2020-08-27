<?php

declare(strict_types=1);

namespace Scoutapm\Connector;

use ErrorException;
use Scoutapm\Connector\Exception\FailedToConnect;
use Scoutapm\Connector\Exception\NotConnected;
use Throwable;
use const AF_INET;
use const AF_UNIX;
use const E_NOTICE;
use const E_STRICT;
use const E_WARNING;
use const SOCK_STREAM;
use function json_encode;
use function pack;
use function register_shutdown_function;
use function restore_error_handler;
use function set_error_handler;
use function socket_clear_error;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_read;
use function socket_send;
use function socket_shutdown;
use function strlen;
use function unpack;

/** @internal */
final class SocketConnector implements Connector
{
    /** @var resource */
    private $socket;

    /** @var bool */
    private $connected = false;

    /** @var ConnectionAddress */
    private $connectionAddress;

    public function __construct(ConnectionAddress $connectionAddress, bool $preEmptivelyAttemptToConnect)
    {
        $this->connectionAddress = $connectionAddress;

        $this->socket = socket_create(
            $this->connectionAddress->isTcpAddress() ? AF_INET : AF_UNIX,
            SOCK_STREAM,
            0
        );

        if (! $preEmptivelyAttemptToConnect) {
            return;
        }

        // Pre-emptive attempt to connect, strictly speaking `__construct` should not have side-effects, so if this
        // fails then swallow it. The `Agent` goes on to call connect() anyway, and handles launching of the core agent.
        try {
            $this->connect();
        } catch (FailedToConnect $failedToConnect) {
        }
    }

    /** @return mixed */
    private function convertErrorsToExceptions(callable $functionToRun)
    {
        // phpcs:disable SlevomatCodingStandard.TypeHints.TypeHintDeclaration.IncorrectReturnTypeHint
        set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0, array $context = []) : bool {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
            E_STRICT | E_NOTICE | E_WARNING
        );
        // phpcs:enable

        try {
            $returnValue = $functionToRun();
        } finally {
            restore_error_handler();
        }

        return $returnValue;
    }

    public function connect() : void
    {
        if ($this->connected()) {
            return;
        }

        try {
            socket_clear_error($this->socket);

            $this->connected = $this->convertErrorsToExceptions(function () {
                if ($this->connectionAddress->isTcpAddress()) {
                    return socket_connect(
                        $this->socket,
                        $this->connectionAddress->tcpBindAddress(),
                        $this->connectionAddress->tcpBindPort()
                    );
                }

                return socket_connect($this->socket, $this->connectionAddress->socketPath());
            });

            register_shutdown_function([&$this, 'shutdown']);
        } catch (Throwable $e) {
            $this->connected = false;
            throw FailedToConnect::fromSocketPathAndPrevious($this->connectionAddress, $e);
        }
    }

    public function connected() : bool
    {
        return $this->connected;
    }

    public function sendCommand(Command $message) : string
    {
        if (! $this->connected()) {
            throw NotConnected::fromSocketPath($this->connectionAddress);
        }

        $serializedJsonString = json_encode($message);

        $size = strlen($serializedJsonString);

        // Socket error is a global state, so we must reset to a known state first...
        socket_clear_error($this->socket);

        if (@socket_send($this->socket, pack('N', $size), 4, 0) === false) {
            throw Exception\FailedToSendCommand::writingMessageSizeToSocket($message, $this->socket, $this->connectionAddress);
        }

        if (@socket_send($this->socket, $serializedJsonString, $size, 0) === false) {
            throw Exception\FailedToSendCommand::writingMessageContentToSocket($message, $this->socket, $this->connectionAddress);
        }

        // Read the response back and drop it. Needed for socket liveness
        $responseLength = @socket_read($this->socket, 4);

        if ($responseLength === false) {
            throw Exception\FailedToSendCommand::readingResponseSizeFromSocket($message, $this->socket, $this->connectionAddress);
        }

        if ($responseLength === '') {
            throw Exception\FailedToSendCommand::fromEmptyResponseSize($message, $this->connectionAddress);
        }

        $dataRead = @socket_read($this->socket, unpack('N', $responseLength)[1]);

        if ($dataRead === false) {
            throw Exception\FailedToSendCommand::readingResponseContentFromSocket($message, $this->socket, $this->connectionAddress);
        }

        return $dataRead;
    }

    public function shutdown() : void
    {
        if (! $this->connected()) {
            return;
        }

        socket_shutdown($this->socket, 2);
        socket_close($this->socket);
    }
}
