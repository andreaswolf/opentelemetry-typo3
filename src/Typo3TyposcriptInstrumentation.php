<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3;

use a9f\OpenTelemetryTYPO3\Attributes\SpanAttributesBag;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use function OpenTelemetry\Instrumentation\hook;

final class Typo3TyposcriptInstrumentation
{
    public const NAME = 'typo3_typoscript';

    public const OBJECT_TYPE = 'typo3.typoscript.object';
    public const TYPOSCRIPT_KEY = 'typo3.typoscript.key';
    public const CONTENT_TABLE = 'typo3.content.table';
    public const CONTENT_UID = 'typo3.content.uid';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'org.typo3.opentelemetry.' . self::NAME,
        );

        hook(
            ContentObjectRenderer::class,
            'cObjGetSingle',
            pre: static function (ContentObjectRenderer $renderer, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $objectName = $params[0];
                $tsKey = $params[2] ?? '[not set]';

                $spanBuilder = self::createSpanBuilder(sprintf('TS Object %s', $objectName), Context::getCurrent(), $instrumentation, $class, $function, $filename, $lineno);
                SpanAttributesBag::instance()
                    ->add(self::OBJECT_TYPE, $objectName)
                    ->add(self::TYPOSCRIPT_KEY, $tsKey)
                    ->add(self::CONTENT_TABLE, $renderer->getCurrentTable())
                    ->add(self::CONTENT_UID, $renderer->data['uid'] ?? null);
                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (ContentObjectRenderer $renderer, array $params, string $output, ?\Throwable $exception) {
                SpanAttributesBag::instance()
                    ->remove(self::OBJECT_TYPE)
                    ->remove(self::TYPOSCRIPT_KEY)
                    ->remove(self::CONTENT_TABLE)
                    ->remove(self::CONTENT_UID);
                self::end($output, $exception);
            }
        );
    }

    /**
     * @param non-empty-string $name
     */
    private static function createSpanBuilder(string $name, ContextInterface $parent, CachedInstrumentation $instrumentation, string $class, string $function, ?string $filename, ?int $lineno): SpanBuilderInterface
    {
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

    private static function end(string $output, ?\Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception) {
            // TODO EXCEPTION_ESCAPED is not part of the non-deprecated arguments anymore, but still part of the spec,
            //      cf. <https://github.com/open-telemetry/semantic-conventions/blob/v1.37.0/docs/exceptions/exceptions-spans.md>
            //      => seems to be a bug in open-telemetry/sem-conv
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }
        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, strlen($output));

        $span->end();
    }
}
