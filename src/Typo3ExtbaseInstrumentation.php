<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3;

use a9f\OpenTelemetryTYPO3\Attributes\SpanAttributesBag;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

use function OpenTelemetry\Instrumentation\hook;

use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ControllerInterface;
use TYPO3\CMS\Extbase\Mvc\Dispatcher;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

class Typo3ExtbaseInstrumentation
{
    public const NAME = 'typo3_extbase';

    public const EXTBASE_PLUGIN = 'typo3.extbase.plugin';
    public const EXTBASE_EXTENSION = 'typo3.extbase.extension';
    public const EXTBASE_CONTROLLER = 'typo3.extbase.controller';
    public const EXTBASE_ACTION = 'typo3.extbase.action';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'org.typo3.opentelemetry.' . self::NAME,
        );

        hook(
            Dispatcher::class,
            'dispatch',
            pre: static function (Dispatcher $dispatcher, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = $params[0];

                $spanBuilder = self::createSpanBuilder(sprintf('%s:%s', $class, $function), Context::getCurrent(), $instrumentation, $request, $class, $function, $filename, $lineno);
                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Dispatcher $dispatcher, array $params, ?ResponseInterface $response, ?\Throwable $exception) {
                self::end($response, $exception);
            }
        );
        hook(
            ControllerInterface::class,
            'processRequest',
            pre: static function (ControllerInterface $controller, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = $params[0];

                $name = sprintf('%s:%s', $class, $function);
                // in TYPO3 v11, RequestInterface does not expose PluginName etc.
                if ($request instanceof Request) {
                    $name = sprintf('%s:%s', $request->getControllerName(), $request->getControllerActionName());
                }

                $spanBuilder = self::createSpanBuilder($name, Context::getCurrent(), $instrumentation, $request, $class, $function, $filename, $lineno);
                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (ControllerInterface $controller, array $params, ?ResponseInterface $response, ?\Throwable $exception) {
                SpanAttributesBag::instance()
                    ->remove(self::EXTBASE_PLUGIN)
                    ->remove(self::EXTBASE_EXTENSION)
                    ->remove(self::EXTBASE_CONTROLLER)
                    ->remove(self::EXTBASE_ACTION);

                self::end($response, $exception);
            }
        );
    }

    /**
     * @param non-empty-string $name
     */
    private static function createSpanBuilder(string $name, ContextInterface $parent, CachedInstrumentation $instrumentation, RequestInterface $request, string $class, string $function, ?string $filename, ?int $lineno): SpanBuilderInterface
    {
        // in TYPO3 v11, RequestInterface does not expose PluginName etc.
        if ($request instanceof Request) {
            SpanAttributesBag::instance()
                ->add(self::EXTBASE_PLUGIN, $request->getPluginName())
                ->add(self::EXTBASE_EXTENSION, $request->getControllerExtensionKey())
                ->add(self::EXTBASE_CONTROLLER, $request->getControllerName())
                ->add(self::EXTBASE_ACTION, $request->getControllerActionName());
        }

        $spanBuilder = $instrumentation->tracer()
            ->spanBuilder($name)
            ->setParent($parent);

        $spanBuilder
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

        $spanBuilder->setSpanKind(SpanKind::KIND_INTERNAL);

        return $spanBuilder;
    }

    private static function end(?ResponseInterface $response, ?\Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        // TODO record API route here

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception) {
            // TODO EXCEPTION_ESCAPED is not part of the non-deprecated arguments anymore, but still part of the spec,
            //      cf. <https://github.com/open-telemetry/semantic-conventions/blob/v1.37.0/docs/exceptions/exceptions-spans.md>
            //      => seems to be a bug in open-telemetry/sem-conv
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        if ($response === null) {
            $span->end();

            return;
        }

        $prop = Globals::responsePropagator();
        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());

        $span->end();
    }
}
