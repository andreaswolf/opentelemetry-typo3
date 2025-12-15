<?php

declare(strict_types=1);

namespace a9f\OpenTelemetryTYPO3;

use a9f\OpenTelemetryTYPO3\Attributes\SpanAttributesBag;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\ReferenceIndexUpdater;
use function OpenTelemetry\Instrumentation\hook;

use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Input\ArgvInput;
use TYPO3\CMS\Backend\Http\Application as BackendApplication;
use TYPO3\CMS\Core\Console\CommandApplication;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Http\AbstractApplication;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Http\Application as FrontendApplication;
use TYPO3\CMS\Frontend\Middleware\FrontendUserAuthenticator;
use TYPO3\CMS\Install\Http\Application as InstallApplication;

/**
 * Instrumentation for the TYPO3 Core classes. Currently adds instrumentation around the entrypoints for CLI/BE/FE/Install
 * Tool
 *
 * Modelled after the integration for Symfony, see <https://github.com/opentelemetry-php/contrib-auto-symfony/tree/main/src>
 */
final class Typo3CoreInstrumentation
{
    public const string NAME = 'typo3';

    public const string ENTRYPOINT = 'typo3.entrypoint';
    public const string REQUEST_ID = 'typo3.request.id';
    public const string FRONTEND_AUTHENTICATED = 'typo3.frontend.authenticated';
    public const string FRONTEND_USERID = 'typo3.frontend.userid';
    public const string FRONTEND_USERNAME = 'typo3.frontend.username';
    public const string FRONTEND_USERGROUP_IDS = 'typo3.frontend.usergroups.ids';
    public const string FRONTEND_USERGROUP_NAMES = 'typo3.frontend.usergroups.names';

    private static RequestId|null $requestId = null;

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'org.typo3.opentelemetry.' . self::NAME,
        );

        hook(
            Bootstrap::class,
            'init',
            post: static function (string $class, array $params, ContainerInterface $container) {
                $requestIdFromContainer = $container->get(RequestId::class);

                if ($requestIdFromContainer !== null) {
                    self::$requestId = $requestIdFromContainer;
                }
            }
        );

        $applications = [
            BackendApplication::class => 'backend',
            FrontendApplication::class => 'frontend',
            InstallApplication::class => 'install',
        ];
        foreach ($applications as $applicationClass => $entrypoint) {
            hook(
                $applicationClass,
                'handle',
                pre: static function (AbstractApplication $application, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $entrypoint) {
                    /** @var ServerRequestInterface $request */
                    $request = $params[0];

                    $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());

                    SpanAttributesBag::instance()
                        ->add(self::ENTRYPOINT, $entrypoint);

                    $requestedPath = $request->getUri()->getPath();
                    // replace numbers in URLs like …/api/rooms/123/… or …/subscription/foo:123/…
                    // TODO check if there is a way to do this more clever
                    $canonicalizedPath = preg_replace(
                        ['#([/:])\d+([/:])#', '#([/:])\d+$#'],
                        ['$1*$2', '$1*'],
                        $requestedPath
                    );
                    $name = sprintf('%s %s', $request->getMethod(), $canonicalizedPath);
                    $spanBuilder = self::createSpanBuilder($name, $parent, $instrumentation, $entrypoint, $class, $function, $filename, $lineno);

                    self::setRequestDataInSpan($spanBuilder, $request);

                    $span = $spanBuilder->startSpan();

                    $parentSpanId = $request->getHeader('X-Trace-Parent')[0] ?? null;
                    if ($parentSpanId) {
                        [, $traceId, $spanId, $flags] = explode('-', $parentSpanId);
                        $parentSpan = SpanContext::createFromRemoteParent($traceId, $spanId, (int)$flags);
                        $span->addLink($parentSpan, ['rel' => 'page']);
                    }

                    // TODO is using $parent correct here?
                    Context::storage()->attach($span->storeInContext($parent));
                },
                post: static function (AbstractApplication $application, array $params, ?ResponseInterface $response, ?\Throwable $exception) {
                    SpanAttributesBag::instance()
                        ->remove(self::ENTRYPOINT);

                    self::end($response, $exception);
                }
            );
        }

        hook(
            CommandApplication::class,
            'run',
            pre: static function (CommandApplication $application, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $input = new ArgvInput();

                $name = sprintf('CLI %s', $input->getFirstArgument() ?? '[unknown]');
                $spanBuilder = self::createSpanBuilder($name, Context::getCurrent(), $instrumentation, 'cli', $class, $function, $filename, $lineno);
                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (CommandApplication $application, array $params, ?ResponseInterface $response, ?\Throwable $exception) {
                self::end($response, $exception);
            }
        );
        hook(
            FrontendUserAuthenticator::class,
            'process',
            post: static function (FrontendUserAuthenticator $authenticator, array $params, ?ResponseInterface $response, ?\Throwable $exception) {
                SpanAttributesBag::instance()
                    ->remove(self::FRONTEND_AUTHENTICATED)
                    ->remove(self::FRONTEND_USERID)
                    ->remove(self::FRONTEND_USERNAME)
                    ->remove(self::FRONTEND_USERGROUP_IDS)
                    ->remove(self::FRONTEND_USERGROUP_NAMES);
            }
        );
        hook(
            FrontendUserAuthentication::class,
            'createUserAspect',
            post: static function (FrontendUserAuthentication $authenticator, array $params, UserAspect $frontendUserAspect, ?\Throwable $exception) {
                $attributes = SpanAttributesBag::instance();
                $attributes->add(self::FRONTEND_AUTHENTICATED, $frontendUserAspect->isLoggedIn());
                if ($frontendUserAspect->isLoggedIn()) {
                    $attributes
                        ->add(self::FRONTEND_USERID, $frontendUserAspect->get('id'))
                        ->add(self::FRONTEND_USERNAME, $frontendUserAspect->get('username'))
                        ->add(self::FRONTEND_USERGROUP_IDS, $frontendUserAspect->get('groupIds'))
                        ->add(self::FRONTEND_USERGROUP_NAMES, $frontendUserAspect->get('groupNames'));
                }
            }
        );


        foreach (
            [
                'start',
                'process_cmdmap',
                'process_datamap',
                'moveRecord',
                'copyPages',
                'copyRecord',
                'localize',
                'inlineLocalizeSynchronize',
                'deleteAction',
                'undeleteRecord',
                'versionizeRecord',
                'discard',
            ] as $method
        ) {
            hook(
                DataHandler::class,
                $method,
                pre: static function (DataHandler $obj, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $method) {
                    $parent = Context::getCurrent();

                    $spanBuilder = $instrumentation->tracer()
                        ->spanBuilder(sprintf('DataHandler::' . $method))
                        ->setParent($parent);

                    $span = $spanBuilder->startSpan();

                    Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                },
                post: static function (DataHandler $obj, array $params, $return, ?\Throwable $exception) {
                    self::endSpanWithoutReturnValue($exception);
                }
            );
        }
        hook(
            ReferenceIndex::class,
            'updateRefIndexTable',
            pre: static function (ReferenceIndex $obj, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $method) {
                $parent = Context::getCurrent();

                $spanBuilder = $instrumentation->tracer()
                    ->spanBuilder('ReferenceIndex::updateRefIndexTable')
                    ->setParent($parent);
                $spanBuilder->setAttribute('refindex.table', $params[0])
                    ->setAttribute('refindex.uid', $params[1]);

                $span = $spanBuilder->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (ReferenceIndex $obj, array $params, $return, ?\Throwable $exception) {
                self::endSpanWithoutReturnValue($exception);
            }
        );
    }

    /**
     * @param non-empty-string $name
     */
    private static function createSpanBuilder(string $name, ContextInterface $parent, CachedInstrumentation $instrumentation, string $typo3Entrypoint, string $class, string $function, ?string $filename, ?int $lineno): SpanBuilderInterface
    {
        $spanBuilder = $instrumentation->tracer()
            ->spanBuilder($name)
            ->setParent($parent);

        $spanBuilder->setAttribute(self::ENTRYPOINT, $typo3Entrypoint);

        $spanBuilder
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

        $spanBuilder->setSpanKind(SpanKind::KIND_SERVER);

        return $spanBuilder;
    }

    private static function setRequestDataInSpan(SpanBuilderInterface $spanBuilder, ServerRequestInterface $request): void
    {
        $spanBuilder
            ->setAttribute(UrlAttributes::URL_FULL, $request->getUri())
            ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
            ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeader('Content-Length'))
            ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
            ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
            ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeader('User-Agent'))
            ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
            ->setAttribute(ServerAttributes::SERVER_PORT, $request->getUri()->getPort());
        if (self::$requestId !== null) {
            $spanBuilder
                ->setAttribute(self::REQUEST_ID, (string)self::$requestId);
        }
    }

    /**
     * @param \Throwable|null $exception
     * @return void
     */
    private static function endSpanWithoutReturnValue(?\Throwable $exception): void
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

        $span->end();
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
            if ($response !== null && $response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }
        }

        if ($response === null) {
            $span->end();

            return;
        }

        if ($response->getStatusCode() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
        $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
        $contentLength = $response->getHeader('Content-Length');

        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $contentLength);

        $prop = Globals::responsePropagator();
        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());

        $span->end();
    }
}
