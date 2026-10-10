<?php

declare(strict_types=1);

namespace Projek\Callable;

use Closure;
use Error;
use Psr\Container\ContainerExceptionInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Entry point: resolve any callable-shape and invoke it with dependency injection.
 *
 * Argument binding mirrors native `call_user_func_array()` — integer keys are positional by
 * order, string keys are named arguments, and a trailing variadic absorbs the rest — except
 * that parameters the caller did not provide are auto-wired from the container (then their
 * default value) instead of raising `ArgumentCountError`.
 */
final class Handler
{
    public function __construct(public readonly ResolverInterface $resolver) {}

    /**
     * Invoke the given callable.
     *
     * Binds `$params` like native `call_user_func_array()` — integer keys are positional by
     * order, string keys are named arguments, unmatched named arguments spill into a trailing
     * variadic — with one addition: parameters the caller did not provide are auto-wired from
     * the container (then their default value) instead of raising `ArgumentCountError`. The
     * binding itself is delegated to `ResolverInterface::resolveArguments()`.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     * @param  array<mixed>  $params  Arguments to bind; anything omitted is auto-wired from the container.
     * @return mixed Whatever the callable returns.
     *
     * @throws ContainerExceptionInterface If the container fails with more than a missing entry (propagated untouched).
     * @throws Error If $params violates native argument-ordering rules.
     * @throws ResolverExceptionInterface If a required parameter cannot be resolved or the callable itself cannot be resolved.
     */
    public function handle(array|callable|object|string $callable, array $params = [])
    {
        $callable = $this->resolver->resolveCallable($callable);

        $args = $this->resolver->resolveArguments(
            $this->createReflection($callable)->getParameters(),
            $params
        );

        // Without a variadic, extra positional arguments are silently dropped — native does
        // the same for userland functions.
        return \call_user_func_array($callable, $args);
    }

    /**
     * Create a reflection instance for the given callable.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     *
     * @throws UnresolvableCallableException If the callable or its target method is invalid.
     * @throws \ReflectionException If reflection fails.
     */
    private function createReflection(array|callable|object|string $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure || \is_string($callable) && \function_exists($callable)) {
            return new ReflectionFunction($callable);
        }

        if (\is_object($callable) && \method_exists($callable, '__invoke')) {
            $callable = [$callable, '__invoke'];
        }

        if (! \is_array($callable) || ! isset($callable[0], $callable[1])) {
            throw UnresolvableCallableException::invalidCallable($callable);
        }

        [$class, $method] = $callable;

        if (! \method_exists($class, $method)) {
            // `__call()`-based "methods" fail `method_exists()`; the bundled `Resolver` rejects
            // them earlier, so this only guards custom `ResolverInterface` implementations that
            // keep such pairs. Malformed pairs cannot reach here: `resolveCallable(): callable`
            // only admits `is_callable()` values, which always carry a string method.
            throw UnresolvableCallableException::methodNotFound($class, $method);
        }

        return new ReflectionMethod($class, $method);
    }
}
