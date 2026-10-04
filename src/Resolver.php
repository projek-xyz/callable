<?php

declare(strict_types=1);

namespace Projek\Callable;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
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
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        if (is_array($callable) && is_string($callable[0])) {
            $callable[0] = $this->resolveFromContainer($callable[0]);
        }

        if ($callable instanceof Closure || is_callable($callable)) {
            /** @var callable */
            return $callable;
        }

        throw new UnresolvableException($callable);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveParameter(ReflectionParameter $param, array $params = []): mixed
    {
        $position = $param->getPosition();

        if (isset($params[$position])) {
            return $params[$position];
        }

        $type = $param->getType();

        $typeName = ($type instanceof ReflectionNamedType && ! $type->isBuiltin())
            ? $type->getName()
            : $param->getName();

        try {
            return $this->container->get($typeName);
        } catch (NotFoundExceptionInterface $err) {
            if ($param->isOptional()) {
                return $param->getDefaultValue();
            }

            throw new DependencyException($typeName, $position, $err);
        }
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
            if (class_exists($entry)) {
                return $this->createInstance($entry);
            }

            throw new UnresolvableException($entry, $err);
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
            throw new UnresolvableException($entry);
        }

        $params = array_map(
            fn ($param) => $this->resolveParameter($param),
            $ref->getConstructor()?->getParameters() ?: []
        );

        return $ref->newInstanceArgs($params);
    }
}
