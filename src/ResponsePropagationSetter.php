<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Shamelessly stolen from the Symfony integration
 *
 * TODO check if we need this
 * @see https://github.com/opentelemetry-php/contrib-auto-symfony/tree/main/src
 * @internal
 */
final class ResponsePropagationSetter implements PropagationSetterInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @return list<string>
     */
    public function keys(mixed $carrier): array
    {
        assert($carrier instanceof ResponseInterface);

        /** @psalm-suppress InvalidReturnStatement */
        return array_keys($carrier->getHeaders());
    }

    public function set(mixed &$carrier, string $key, string $value): void
    {
        assert($carrier instanceof ResponseInterface);

        $carrier = $carrier->withHeader($key, $value);
    }
}
