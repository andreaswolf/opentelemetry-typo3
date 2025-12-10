<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use TYPO3\CMS\Core\Http\Request;

/**
 * Shamelessly stolen from the Symfony integration
 *
 * TODO check if we need this
 * @see https://github.com/opentelemetry-php/contrib-auto-symfony/tree/main/src
 * @internal
 */
final class RequestPropagationGetter implements PropagationGetterInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param Request $carrier
     * @return list<string>
     */
    public function keys($carrier): array
    {
        assert($carrier instanceof Request);

        return array_keys($carrier->getHeaders());
    }

    /**
     * @param Request $carrier
     */
    public function get($carrier, string $key): ?string
    {
        assert($carrier instanceof Request);

        return $carrier->getHeader($key)[0] ?? null;
    }
}
