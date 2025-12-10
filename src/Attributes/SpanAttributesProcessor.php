<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3\Attributes;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * Custom span processor that adds all attributes from {@see SpanAttributesBag} to each newly created span.
 */
final readonly class SpanAttributesProcessor implements SpanProcessorInterface
{
    public function __construct(private SpanAttributesBag $attributes) {}

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        foreach ($this->attributes->getAll() as $key => $value) {
            $span->setAttribute($key, $value);
        }
    }

    public function onEnd(ReadableSpanInterface $span): void {}

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
