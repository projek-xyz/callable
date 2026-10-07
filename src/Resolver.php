<?php

declare(strict_types=1);

namespace Projek\Callable;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class Resolver implements ResolverInterface
{
    public function __construct(
        private ContainerInterface $container
    ) {
        // .
    }

    /**
     * {@inheritDoc}
     */
    public function resolveCallable($callable): callable
    {
        // 'Class::method' string shorthand → pair form.
        if (\is_string($callable) && \str_contains($callable, '::')) {
            $callable = \explode('::', $callable, 2);
        }

        // Static targets resolve to [Class::method] as-is: a static call never
        // needs an instance, so the container is not consulted and the class is
        // not instantiated — constructor side effects (and constructor
        // dependencies the container cannot build) must not get in the way.
        // Uniform rule for the string and array pair forms.
        if (
            \is_array($callable) && \count($callable) === 2
            && isset($callable[0], $callable[1])
            && \is_string($callable[0]) && \is_string($callable[1])
        ) {
            [$class, $method] = $callable;

            if (\class_exists($class) && \method_exists($class, $method) && (new ReflectionMethod($class, $method))->isStatic()) {
                return [$class, $method];
            }

            try {
                // Non-static (or unknown) target: resolve an instance.
                $callable[0] = $this->container->get($class);
            } catch (NotFoundExceptionInterface $err) {
                if (! \class_exists($class)) {
                    // Neither registered nor an existing class: name the entry so the
                    // typo (missing namespace, wrong FQCN) is obvious at a glance.
                    throw UnresolvableCallableException::invalidCallable($class, $err);
                }

                $callable[0] = $this->resolveInstance($class);
            }
        }

        // __call()-based "methods" pass is_callable() but have no real method to
        // reflect, so parameter type-hints could never be honoured — reject them
        // here with a diagnosable error instead of failing later in Handler.
        if (
            \is_array($callable) && isset($callable[0], $callable[1])
            && \is_object($callable[0]) && \is_string($callable[1])
            && ! \method_exists($callable[0], $callable[1])
        ) {
            throw UnresolvableCallableException::methodNotFound($callable[0], $callable[1]);
        }

        // A bare class-string of an invokable class resolves to its instance
        // (Handler reflects __invoke() on it). Class-strings without __invoke()
        // are not callables and fall through to the exception below.
        if (\is_string($callable) && \class_exists($callable) && \method_exists($callable, '__invoke')) {
            try {
                $callable = $this->container->get($callable);
            } catch (NotFoundExceptionInterface $err) {
                if (! \class_exists($callable)) {
                    throw UnresolvableCallableException::invalidCallable($callable, $err);
                }

                $callable = $this->resolveInstance($callable);
            }
        }

        if ($callable instanceof Closure || \is_callable($callable)) {
            /** @var callable */
            return $callable;
        }

        throw UnresolvableCallableException::invalidCallable($callable);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveParameter(ReflectionParameter $param): mixed
    {
        // A variadic has no meaningful single value: Handler splices the
        // arguments itself and resolveInstance() skips variadic constructor
        // parameters, so only a direct call (specs, custom callers) can land
        // here — keep container lookup from packing a spurious value.
        if ($param->isVariadic()) {
            return [];
        }

        // By-reference parameters must be provided by the caller: a value pulled
        // from the container (or a default) is a temporary — PHP lets the call
        // succeed, but every write through the reference is discarded when the
        // call returns, so an output-style callable would silently produce
        // nothing.
        if ($param->isPassedByReference()) {
            throw new UnresolvableParameterException($param, 'by-reference parameter must be provided explicitly');
        }

        $type = $param->getType();
        $typeName = null;
        $notFound = null;

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            // Class-typed parameter: fulfil it from the container. Only
            // NotFoundExceptionInterface is handled: an absent entry is a
            // resolution outcome that falls through to the default chain below.
            // Any other ContainerExceptionInterface (broken factory, circular
            // reference) is the container orchestrator's failure, not ours — it
            // propagates untouched so third parties catch exactly what their
            // container threw.
            $typeName = $type->getName();

            try {
                return $this->container->get($typeName);
            } catch (NotFoundExceptionInterface $err) {
                $notFound = $err;
            }
        } elseif ($type !== null && ! $type instanceof ReflectionNamedType) {
            // Union/intersection types are deliberately NOT resolved from the
            // container (decided: too ambiguous). Keep the declared type for the
            // diagnostic so the error names what the developer wrote
            // (e.g. "A|B") instead of an arbitrary parameter name.
            $typeName = (string) $type;
        }

        // Defaults are consulted only when the container cannot provide the type.
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // Untyped parameters fall back to a lookup by bare parameter name.
        // Built-in typed parameters deliberately do NOT: `fn (int $count = 3)`
        // must keep its own default instead of being shadowed by an unrelated
        // container entry registered under that name.
        if ($type === null) {
            // Same contract as the class-typed lookup above: NotFound falls
            // through, any other container failure propagates untouched.
            try {
                return $this->container->get($param->getName());
            } catch (NotFoundExceptionInterface $err) {
                $notFound = $err;
            }
        }

        throw new UnresolvableParameterException($param, $typeName, $notFound);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveInstance(string $entry): object
    {
        $ref = new ReflectionClass($entry);

        if (! $ref->isInstantiable()) {
            throw UnresolvableCallableException::notInstantiable($entry);
        }

        $params = array_map(
            fn ($param) => $this->resolveParameter($param),
            // A variadic constructor must receive no argument at all: mapping
            // the guard's [] back in would pack $args as [[]] (and raise a
            // TypeError for typed variadics) instead of the native zero-arg
            // construction. The variadic is always the last parameter.
            \array_filter(
                $ref->getConstructor()?->getParameters() ?: [],
                fn ($param) => ! $param->isVariadic()
            )
        );

        return $ref->newInstanceArgs($params);
    }
}
