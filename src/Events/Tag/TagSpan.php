<?php

declare(strict_types=1);

namespace Scoutapm\Events\Tag;

use DateTime;
use DateTimeZone;
use Scoutapm\Events\Request\RequestId;
use Scoutapm\Events\Span\SpanId;

use function sprintf;

/**
 * Naming perhaps is a little off, it perhaps would've made more sense to name this `SpanTag`, but the JSON expects
 * imperative tense, so read this as "tag the span" rather than "this is a span's tag". This naming is kept to be
 * consistent with the payload that the core agent expects.
 *
 * @internal
 */
class TagSpan extends Tag
{
    /** @var SpanId */
    protected $spanId;

    /** @param mixed $value */
    public function __construct(string $tag, $value, RequestId $requestId, SpanId $spanId, ?float $timestamp = null)
    {
        parent::__construct($tag, $value, $requestId, $timestamp);

        $this->spanId = $spanId;
    }

    /**
     * @return string[][][]|array[][][]|bool[][][]|null[][][]
     * @psalm-return list<
     *      array{
     *          TagSpan: array{
     *              request_id: string,
     *              span_id: string,
     *              tag: string,
     *              value: mixed,
     *              timestamp: string
     *          }
     *      }
     * >
     */
    public function jsonSerialize(): array
    {
        // Format the timestamp
        $timestamp = DateTime::createFromFormat('U.u', sprintf('%.6F', $this->timestamp));
        $timestamp->setTimeZone(new DateTimeZone('UTC'));
        $timestamp = $timestamp->format('Y-m-d\TH:i:s.u\Z');

        return [
            [
                'TagSpan' => [
                    'request_id' => $this->requestId->toString(),
                    'span_id' => $this->spanId->toString(),
                    'tag' => $this->tag,
                    'value' => $this->value,
                    'timestamp' => $timestamp,
                ],
            ],
        ];
    }
}
