<?php

namespace Projek\Callable;

use ReflectionParameter;

interface ResolverInterface
{
    /**
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     * @return array{class-string<T>|T,string}|callable|T|string
     *
     * @throws UnresolvableException
     */
    public function resolveCallable($callable): callable;

    /**
     * @throws DependencyException
     */
    public function resolveParameter(ReflectionParameter $param): mixed;
}
