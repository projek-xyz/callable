<?php

declare(strict_types=1);

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
     * @throws UnresolvableCallableException
     */
    public function resolveCallable($callable): callable;

    /**
     * @throws ContainerExceptionInterface A container failure other than a missing entry — propagated untouched, never wrapped.
     * @throws UnresolvableParameterException
     */
    public function resolveParameter(ReflectionParameter $param): mixed;

    /**
     * @template T of object
     *
     * @param  class-string<T>  $entry
     * @return T
     */
    public function resolveInstance(string $entry): object;
}
