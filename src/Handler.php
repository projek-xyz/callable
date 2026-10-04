<?php

declare(strict_types=1);

namespace Projek\Callable;

use Closure;
use Error;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
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
     * Binds $params like native call_user_func_array() — integer keys are
     * positional by order, string keys are named arguments, unmatched named
     * arguments spill into a trailing variadic — with one addition: parameters
     * the caller did not provide are auto-wired from the container (then their
     * default value) instead of raising ArgumentCountError.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     * @param  array<mixed>  $params
     *
     * @throws DependencyException If a required parameter cannot be resolved.
     * @throws UnresolvableException If the callable itself cannot be resolved.
     * @throws Error If $params violates native argument-ordering rules.
     */
    public function handle(array|callable|object|string $callable, array $params = [])
    {
        $callable = $this->resolver->resolveCallable($callable);
        $parameters = $this->createReflection($callable)->getParameters();

        $normalized = $this->normalizeArguments($params);

        $variadic = null;
        $declared = [];

        foreach ($parameters as $param) {
            if ($param->isVariadic()) {
                $variadic = $param;

                continue;
            }

            $declared[$param->getName()] = true;
        }

        // Native call_user_func_array() rejects named arguments that match no
        // declared parameter; only a trailing variadic may absorb them.
        if ($variadic === null) {
            foreach ($normalized as $key => $value) {
                if (! is_int($key) && ! isset($declared[$key])) {
                    throw new Error('Unknown named parameter $'.$key);
                }
            }
        }

        $args = [];
        $consumed = [];

        foreach ($parameters as $param) {
            if ($param->isVariadic()) {
                continue;
            }

            $position = $param->getPosition();
            $name = $param->getName();

            if (array_key_exists($position, $normalized)) {
                // Native throws when a named argument targets a parameter that a
                // positional argument already filled — mirror that instead of
                // silently preferring one of them.
                if (array_key_exists($name, $normalized)) {
                    throw new Error(sprintf('Named parameter $%s overwrites previous argument', $name));
                }

                $args[$position] = &$normalized[$position];
                $consumed[$position] = true;
            } elseif (array_key_exists($name, $normalized)) {
                // Named arguments bind by parameter name; the key must not also
                // leak into a trailing variadic.
                $args[$position] = &$normalized[$name];
                $consumed[$name] = true;
            } else {
                // Not provided: auto-wire it (class type from the container →
                // default → untyped name lookup) or throw.
                $args[$position] = $this->resolver->resolveParameter($param);
            }
        }

        if ($variadic !== null) {
            // Splice every remaining argument into the variadic: leftover
            // positional keys are renumbered from zero (so $args looks exactly
            // like a native call: f('x', 'a', 'b') packs [...$args] as
            // [0 => 'a', 1 => 'b'], never [1 => ..., 2 => ...]) and unmatched
            // named arguments keep their string keys.
            foreach ($normalized as $key => &$value) {
                if (isset($consumed[$key])) {
                    continue;
                }

                if (is_int($key)) {
                    $args[] = &$value;
                } else {
                    $args[$key] = &$value;
                }
            }

            unset($value);
        }

        // Without a variadic, extra positional arguments are silently dropped —
        // native does the same for userland functions.
        return call_user_func_array($callable, $args);
    }

    /**
     * Normalise the caller's arguments to native semantics: integer keys are
     * POSITIONAL BY ORDER (the key values themselves are ignored — [5 => 'x']
     * feeds the first parameter, and the sparse keys left behind by
     * array_filter() still line up), string keys are NAMED. References into
     * $params are preserved so by-reference parameters keep mutating the
     * caller's variables.
     *
     * @throws Error If a positional argument follows a named one.
     */
    private function normalizeArguments(array $params): array
    {
        $normalized = [];
        $seenNamed = false;

        foreach ($params as $key => &$value) {
            if (is_int($key)) {
                if ($seenNamed) {
                    throw new Error('Cannot use positional argument after named argument');
                }

                $normalized[] = &$value;
            } else {
                $seenNamed = true;
                $normalized[$key] = &$value;
            }
        }

        unset($value);

        return $normalized;
    }

    /**
     * Create a reflection instance for the given callable.
     *
     * @template T of object
     *
     * @param  array{class-string<T>|T,string}|callable|T|string  $callable
     *
     * @throws UnresolvableException If the callable or its target method is invalid.
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

        if (! \is_array($callable) || ! isset($callable[0], $callable[1])) {
            throw UnresolvableException::invalidCallable($callable);
        }

        [$class, $method] = $callable;

        // __call()-based "methods" fail method_exists(); the bundled Resolver
        // rejects them earlier, so this only guards custom ResolverInterface
        // implementations that keep such pairs. Malformed pairs cannot reach
        // here: resolveCallable(): callable only admits is_callable() values,
        // which always carry a string method.
        if (! method_exists($class, $method)) {
            throw UnresolvableException::methodNotFound($class, $method);
        }

        return new ReflectionMethod($class, $method);
    }
}
