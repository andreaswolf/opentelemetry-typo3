<?php

declare(strict_types=1);

use a9f\OpenTelemetryTYPO3\Attributes\SpanAttributesBag;
use a9f\OpenTelemetryTYPO3\Attributes\SpanAttributesProcessor;
use a9f\OpenTelemetryTYPO3\Typo3CoreInstrumentation;
use a9f\OpenTelemetryTYPO3\Typo3ExtbaseInstrumentation;
use a9f\OpenTelemetryTYPO3\Typo3TyposcriptInstrumentation;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Metrics\MeterProviderFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry auto-instrumentation', E_USER_WARNING);

    return;
}

if (Sdk::isDisabled() || Sdk::isInstrumentationDisabled(Typo3CoreInstrumentation::NAME)) {
    return;
}

/**
 * Registers our span attributes processor globally. This must happen here because we cannot later add a processor to
 * the existing tracer provider. This is also why we copied a part of {@see \OpenTelemetry\SDK\SdkAutoloader::environmentBasedInitializer()}
 * here.
 *
 * @see \OpenTelemetry\SDK\SdkAutoloader::environmentBasedInitializer()
 */
(static function (): void {

    /**
     * Create a custom trace provider that passes span attributes via {@see SpanAttributesProcessor}.
     *
     * @see \OpenTelemetry\SDK\SdkAutoloader::environmentBasedInitializer for where this code was borrowed from
     */
    Globals::registerInitializer(static function (Configurator $configurator): Configurator {
        $resource = ResourceInfoFactory::defaultResource();
        $exporter = (new ExporterFactory())->create();
        $emitMetrics = Configuration::getBoolean(Variables::OTEL_PHP_INTERNAL_METRICS_ENABLED);
        $meterProvider = (new MeterProviderFactory())->create($resource);
        $spanProcessor = (new SpanProcessorFactory())->create($exporter, $emitMetrics ? $meterProvider : null);
        $sampler = (new SamplerFactory())->create();

        $tracerProvider = (new TracerProviderBuilder())
            ->setSampler($sampler)
            ->addSpanProcessor(new SpanAttributesProcessor(SpanAttributesBag::instance()))
            ->addSpanProcessor($spanProcessor)
            ->build();

        ShutdownHandler::register($tracerProvider->shutdown(...));

        return $configurator
            ->withTracerProvider($tracerProvider);
    });
})();
if (Sdk::isInstrumentationDisabled(Typo3CoreInstrumentation::NAME) === false) {
    Typo3CoreInstrumentation::register();
}
if (Sdk::isInstrumentationDisabled(Typo3ExtbaseInstrumentation::NAME) === false) {
    Typo3ExtbaseInstrumentation::register();
}
if (Sdk::isInstrumentationDisabled(Typo3TyposcriptInstrumentation::NAME) === false) {
    Typo3TyposcriptInstrumentation::register();
}
