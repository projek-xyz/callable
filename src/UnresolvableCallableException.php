<?php

declare(strict_types=1);

namespace Projek\Callable;

use InvalidArgumentException;
use Throwable;

final class UnresolvableCallableException extends InvalidArgumentException implements ResolverExceptionInterface
{
    /**
     * Construction is routed through the named factories below: each failure
     * site knows WHY resolution failed, and a bare message string cannot
     * express that reason on its own. The constructor is private so every
     * throw site is forced to pick the precise diagnosis.
     */
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The given value is not a callable and cannot be turned into one:
     * scalars, plain class-strings, malformed arrays, or pairs whose method
     * exists but cannot be called from here (private method).
     */
    public static function invalidCallable(mixed $callable, ?Throwable $previous = null): static
    {
        $callable = \is_object($callable) ? \get_class($callable) : $callable;

        if (\is_string($callable)) {
            $message = \sprintf('Instance of %s is not resolvable', $callable);
        } elseif (\is_array($callable) && isset($callable[0], $callable[1])) {
            $message = \sprintf(
                '%s::%s() is not resolvable',
                \is_object($callable[0]) ? \get_class($callable[0]) : $callable[0],
                $callable[1]
            );
        } else {
            // Scalars, null and malformed arrays (empty array, pair without a
            // method) match no branch above: every input must yield a
            // diagnosable message instead of an empty one.
            $message = \sprintf('%s is not resolvable', self::describe($callable));
        }

        return new self($message, $previous);
    }

    /**
     * The entry names a class-like that can never be instantiated (enum,
     * abstract class): the instantiate-and-inject path is impossible by
     * definition, and reflection would leak a raw ReflectionException.
     */
    public static function notInstantiable(string $entry): static
    {
        return new self(\sprintf('%s is not instantiable', $entry));
    }

    /**
     * The pair's method does not exist — including __call()-only "methods",
     * which pass is_callable() but have no real method to reflect.
     */
    public static function methodNotFound(string|object $class, string $method): static
    {
        return new self(\sprintf(
            'Method %s::%s() does not exist',
            \is_object($class) ? \get_class($class) : $class,
            $method
        ));
    }

    /**
     * Render a scalar, null or array as a short message fragment — strings and
     * objects are handled by invalidCallable() before describe() is reached.
     */
    private static function describe(mixed $value): ?string
    {
        if (\is_scalar($value) || $value === null) {
            return \var_export($value, true);
        }

        return \gettype($value);
    }
}
