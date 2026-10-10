<?php

declare(strict_types=1);

namespace Projek\Callable;

use Error;
use Psr\Container\ContainerExceptionInterface;
use ReflectionParameter;

interface ResolverInterface
{
    /**
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
     * Full argument-list resolution: native binding rules (integer keys are
     * positional-by-order, string keys are named, unmatched keys spill into a
     * trailing variadic, native Error ordering rules) + resolveParameter()
     * for everything the caller did not provide. References into $provided
     * are preserved so by-reference parameters keep mutating the caller's
     * variables.
     *
     * @param  ReflectionParameter[]  $params
     * @param  array<mixed>  $provided
     * @return array<mixed>
     *
     * @throws Error If $provided breaks native argument rules (positional-after-named, overwrite, unknown named).
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableParameterException If a parameter cannot be auto-wired.
     */
    public function resolveArguments(array $params, array $provided): array;

    /**
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableParameterException
     */
    public function resolveParameter(ReflectionParameter $param): mixed;

    /**
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
