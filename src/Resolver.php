<?php

declare(strict_types=1);

namespace Projek\Callable;

use Closure;
use Psr\Container\ContainerExceptionInterface;
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

            // Non-static (or unknown) target: resolve an instance.
            $callable[0] = $this->resolveFromContainer($class);
        }

        // __call()-based "methods" pass is_callable() but have no real method to
        // reflect, so parameter type-hints could never be honoured — reject them
        // here with a diagnosable error instead of failing later in Handler.
        if (
            \is_array($callable) && isset($callable[0], $callable[1])
            && \is_object($callable[0]) && \is_string($callable[1])
            && ! \method_exists($callable[0], $callable[1])
        ) {
            throw UnresolvableException::methodNotFound($callable[0], $callable[1]);
        }

        // A bare class-string of an invokable class resolves to its instance
        // (Handler reflects __invoke() on it). Class-strings without __invoke()
        // are not callables and fall through to the exception below.
        if (\is_string($callable) && \class_exists($callable) && \method_exists($callable, '__invoke')) {
            $callable = $this->resolveFromContainer($callable);
        }

        if ($callable instanceof Closure || \is_callable($callable)) {
            /** @var callable */
            return $callable;
        }

        throw UnresolvableException::invalidCallable($callable);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveParameter(ReflectionParameter $param): mixed
    {
        // A variadic has no meaningful single value: Handler splices the
        // arguments itself and createInstance() skips variadic constructor
        // parameters, so only a direct call (specs, custom callers) can land
        // here — keep container lookup from packing a spurious value.
        if ($param->isVariadic()) {
            return [];
        }

        $position = $param->getPosition();
        $name = $param->getName();

        // By-reference parameters must be provided by the caller: a value pulled
        // from the container (or a default) is a temporary — PHP lets the call
        // succeed, but every write through the reference is discarded when the
        // call returns, so an output-style callable would silently produce
        // nothing.
        if ($param->isPassedByReference()) {
            throw new DependencyException($name, $position, \sprintf(
                'by-reference parameter $%s must be provided explicitly', $name
            ));
        }

        $type = $param->getType();
        $typeName = null;
        $notFound = null;

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            // Class-typed parameter: fulfil it from the container.
            $typeName = $type->getName();

            try {
                return $this->container->get($typeName);
            } catch (NotFoundExceptionInterface $err) {
                $notFound = $err;
            } catch (ContainerExceptionInterface $err) {
                // A generic PSR-11 failure is a real error (broken factory,
                // circular reference), not "entry missing" — wrap it instead of
                // quietly falling back to a default.
                throw new DependencyException($typeName, $position, null, $err);
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
            try {
                return $this->container->get($name);
            } catch (NotFoundExceptionInterface $err) {
                $notFound = $err;
            } catch (ContainerExceptionInterface $err) {
                throw new DependencyException($name, $position, null, $err);
            }
        }

        throw new DependencyException($typeName ?? $name, $position, null, $notFound);
    }

    /**
     * @template T of object
     *
     * @param  string|class-string<T>  $entry
     * @return ($entry is class-string<T> ? T : object)
     *
     * @throws UnresolvableException
     */
    private function resolveFromContainer(string $entry)
    {
        try {
            return $this->container->get($entry);
        } catch (NotFoundExceptionInterface $err) {
            if (\class_exists($entry)) {
                return $this->createInstance($entry);
            }

            // Neither registered nor an existing class: name the entry so the
            // typo (missing namespace, wrong FQCN) is obvious at a glance.
            throw UnresolvableException::invalidCallable($entry, $err);
        } catch (ContainerExceptionInterface $err) {
            // PSR-11 permits get() to throw a plain ContainerExceptionInterface
            // — wrap it so callers only ever deal in this library's exceptions
            // (ResolverInterface documents @throws UnresolvableException).
            throw UnresolvableException::invalidContainerEntry($entry, $err);
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $entry
     * @return T
     */
    private function createInstance(string $entry): object
    {
        $ref = new ReflectionClass($entry);

        if (! $ref->isInstantiable()) {
            throw UnresolvableException::notInstantiable($entry);
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
