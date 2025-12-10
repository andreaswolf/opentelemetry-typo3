<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3\Attributes;

/**
 * Shared container for span attributes that should be added to all spans.
 *
 * Instrumentations should add attributes here when they want to pass them to child spans, e.g. user IDs, request IDs,
 * plugin names etc.
 *
 * @see SpanAttributesProcessor for the processor that automatically adds the attributes to spans.
 */
final class SpanAttributesBag
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<non-empty-string, scalar> $attributes
     */
    public function __construct(private array $attributes = []) {}

    /**
     * @param non-empty-string $key
     */
    public function add(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function remove(string $key): self
    {
        unset($this->attributes[$key]);
        return $this;
    }

    /**
     * @return array<non-empty-string, scalar>
     */
    public function getAll(): array
    {
        return $this->attributes;
    }
}
