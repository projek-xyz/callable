<?php

declare(strict_types=1);

namespace Projek\Callable;

use Error;
use Psr\Container\ContainerExceptionInterface;
use ReflectionParameter;

/**
 * Resolve given callable-shape and its dependencies
 */
interface ResolverInterface
{
    /**
     * Resolve given callable
     *
     * Accepts the same shapes as `Handler::handle()`: a `Class::method` string, a `[Class, method]`
     * pair, a plain function name, a closure, or an invokable class-string/object. Static pairs
     * come back untouched — the container is never consulted and the class is not instantiated;
     * a non-static pair has its receiver resolved from the container, or built fresh when
     * unregistered; an invokable class-string resolves to its instance; anything already
     * callable passes through unchanged.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     * @return array{class-string<T>|T,string}|callable|T|string
     *
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableCallableException
     */
    public function resolveCallable($callable): callable;

    /**
     * Resolve given arguments
     *
     * Native binding rules (integer keys are positional-by-order, string keys are named, unmatched
     * keys spill into a trailing variadic, native `Error` ordering rules) + `resolveParameter()`
     * for everything the caller did not provide. References into `$provided` are preserved so
     * by-reference parameters keep mutating the caller's variables.
     *
     * @param  array<ReflectionParameter>  $params
     * @param  array<mixed>  $provided
     * @return array<mixed>
     *
     * @throws Error If $provided breaks native argument rules (positional-after-named, overwrite, unknown named).
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableParameterException If a parameter cannot be auto-wired.
     */
    public function resolveArguments(array $params, array $provided): array;

    /**
     * Resolve given parameter
     *
     * Auto-wiring for a parameter the caller did not provide, in precedence order: variadics
     * yield `[]` (a guard — `resolveArguments()` splices their arguments itself), by-reference
     * parameters are rejected outright (a container value is a temporary; writes through the
     * reference would be discarded), a class type-hint is fetched from the container, the
     * declared default applies next, and untyped parameters additionally fall back to a lookup
     * by their bare name. Union/intersection types are never looked up — the declared type is
     * only kept for the diagnostic.
     *
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableParameterException
     */
    public function resolveParameter(ReflectionParameter $param): mixed;

    /**
     * Instantiate given class-name string
     *
     * @template T of object
     *
     * @param  class-string<T>  $className
     * @param  array<mixed>  $args  bound against the constructor exactly like handle() binds $params.
     * @return T
     *
     * @throws Error If $args breaks native argument rules (positional-after-named, overwrite, unknown named).
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableCallableException If the entry is not instantiable.
     * @throws UnresolvableParameterException If a constructor parameter cannot be resolved.
     */
    public function resolveInstance(string $className, array $args = []): object;
}
