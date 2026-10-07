<?php

declare(strict_types=1);

namespace Projek\Callable;

use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Throwable;

final class DependencyException extends RuntimeException
{
    public function __construct(
        public readonly ReflectionParameter $param,
        ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        // Native diagnostics count arguments from 1 ("Argument #1") while
        // reflection is 0-based — render + 1 to mirror TypeError phrasing.
        $message = \sprintf(
            '%s(): Argument #%d ($%s) is not resolvable',
            self::label($param),
            $param->getPosition() + 1,
            $param->getName()
        );

        // Optional detail adds context beyond the parameter itself (the type
        // that could not be fetched, or why a by-reference parameter is
        // unresolvable) without changing the base message format.
        if ($detail !== null) {
            $message .= ': '.$detail;
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * Label the declaring callable the way native error messages do:
     * "Class::method" for methods, the plain name for functions, and a bare
     * "{closure}" for anonymous functions. Closure reflection names vary by
     * context — "{closure}", "{closure:file:line}" on newer PHP, or a
     * class-bound closure reported as a method of its binding scope — so
     * anything carrying the marker is normalized to keep the message stable
     * across versions, runners, and edits.
     */
    private static function label(ReflectionParameter $param): string
    {
        $function = $param->getDeclaringFunction();
        $name = $function->getName();

        // A declared function name can never contain '{', so the marker
        // unambiguously identifies an anonymous function. This check must run
        // before the method branch: a closure bound to a class scope (how
        // test runners invoke specs) reflects as a ReflectionMethod whose
        // "name" is "{closure:...}" and whose declaring class is only the
        // binding scope.
        if (\str_contains($name, '{closure')) {
            return '{closure}';
        }

        if ($function instanceof ReflectionMethod) {
            return $function->getDeclaringClass()->getName().'::'.$name;
        }

        return $name;
    }
}
