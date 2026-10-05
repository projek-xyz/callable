<?php

namespace Projek\Callable;

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
     * @throws UnresolvableException
     */
    public function resolveCallable($callable): callable;

    /**
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws DependencyException
     */
    public function resolveParameter(ReflectionParameter $param): mixed;
}
