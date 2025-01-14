<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Theme\ThemeManager;
use Drupal\page_cache\StackMiddleware\PageCache;

final class DrupalInstrumentation
{
    public const NAME = 'drupal';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.drupal');

        hook(
            DrupalKernel::class,
            'handle',
            pre: static function (
                DrupalKernel $kernel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $parent = Globals::propagator()->extract($request->headers->all());

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s', $request?->getMethod() ?? 'unknown'))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::URL_FULL, $request->getUri())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->headers->get('Content-Length'))
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getPathInfo())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->headers->get('User-Agent'))
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getPort())
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                return [];
            },
            post: static function (
                DrupalKernel $kernel,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();

                $span = Span::fromContext($scope->context());

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                if (null !== $request) {
                    $routeName = $request->attributes->get('_route', '');

                    if ('' !== $routeName) {
                        /** @psalm-suppress ArgumentTypeCoercion */
                        $span
                            ->updateName(sprintf('%s %s', $request->getMethod(), $routeName))
                            ->setAttribute(TraceAttributes::HTTP_ROUTE, $routeName);
                    }
                }

                if (null !== $exception) {
                    $span->recordException($exception, [
                        TraceAttributes::EXCEPTION_ESCAPED => true,
                    ]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                if (null === $response) {
                    $span->end();

                    return;
                }

                if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                $contentLength = $response->headers->get('Content-Length');
                /** @psalm-suppress PossiblyFalseArgument */
                if (null === $contentLength && is_string($response->getContent())) {
                    $contentLength = \strlen($response->getContent());
                }

                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $contentLength);

                $span->end();
            }
        );

        hook(
            DrupalKernel::class,
            'initializeSettings',
            pre: static function (
                DrupalKernel $kernel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder("INITIALIZE SETTINGS")
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                DrupalKernel $kernel,
                array $params,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();

                $span = Span::fromContext($scope->context());

                $span->end();
            }
        );

        hook(
            PageCache::class,
            'handle',
            pre: static function (
                PageCache $cache,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder("PAGE CACHE")
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                PageCache $cache,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();

                $span = Span::fromContext($scope->context());

                $span->end();
            }
        );
    }
}
