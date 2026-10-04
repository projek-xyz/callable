<?php

declare(strict_types=1);

namespace Projek\Callable;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RdKafka\Exception;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

final class Handler
{
    private ResolverInterface $resolver;

    public function __construct(
        private ContainerInterface $container
    ) {
        try {
            $this->resolver = $container->get(ResolverInterface::class);
        } catch (NotFoundExceptionInterface) {
            $this->resolver = new Resolver($container);
        }
    }

    /**
     * Invoke the given callable.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     */
    public function handle(array|callable|object|string $callable, array $params = [])
    {
        $callable = $this->resolver->resolveCallable($callable);

        $params = array_map(
            fn ($param) => $this->resolver->resolveParameter($param, $params),
            $this->createReflection($callable)->getParameters()
        );

        return call_user_func_array($callable, $params);
    }

    /**
     * Create a reflection instance for the given callable.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     *
     * @throws Exception If a non-static method is called statically.
     * @throws \ReflectionException If reflection fails.
     */
    private function createReflection(array|callable|object|string $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure || \is_string($callable) && function_exists($callable)) {
            return new ReflectionFunction($callable);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            $callable = [$callable, '__invoke'];
        }

        if (! \is_array($callable)) {
            throw new Exception('Invalid callable: '.\var_export($callable, true));
        }

        [$class, $method] = $callable;

        if (! method_exists($class, $method)) {
            throw new Exception('Method '.$method.' does not exist on class '.$class);
        }

        $ref = new ReflectionMethod($class, $method);

        // If trying to statically call a non-static method
        if (! $ref->isStatic() && \is_string($callable[0])) {
            throw new Exception(\sprintf(
                'Non-static method %s should not be called statically',
                \implode('::', $callable)
            ));
        }

        return $ref;
    }
}
