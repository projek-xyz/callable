<?php

declare(strict_types=1);

namespace Projek\Callable;

use Error;

trait ParametersHelper
{
    private ResolverInterface $resolver;

    /**
     * Build the final argument list for the callable.
     *
     * Binding the caller's $provided arguments against the declared $parameters:
     * integer keys are POSITIONAL BY ORDER (the key values themselves are
     * ignored — [5 => 'x'] feeds the first parameter, and the sparse keys left
     * behind by array_filter() still line up), string keys are NAMED, and
     * parameters the caller did not provide are auto-wired from the container
     * (then their default value). References into $provided are preserved so
     * by-reference parameters keep mutating the caller's variables; leftover
     * arguments spill into a trailing variadic, exactly like a native call.
     *
     * @throws Error If $provided breaks native argument rules (ordering, overwrite, unknown named).
     * @throws UnresolvableParameterException If a parameter cannot be auto-wired from the container.
     */
    private function buildArguments(array $parameters, array $provided): array
    {
        $normalized = [];
        $seenNamed = false;

        foreach ($provided as $key => &$value) {
            if (\is_int($key)) {
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
                if (! \is_int($key) && ! isset($declared[$key])) {
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

            if (\array_key_exists($position, $normalized)) {
                // Native throws when a named argument targets a parameter that a
                // positional argument already filled — mirror that instead of
                // silently preferring one of them.
                if (\array_key_exists($name, $normalized)) {
                    throw new Error(\sprintf('Named parameter $%s overwrites previous argument', $name));
                }

                $args[$position] = &$normalized[$position];
                $consumed[$position] = true;
            } elseif (\array_key_exists($name, $normalized)) {
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

                if (\is_int($key)) {
                    $args[] = &$value;
                } else {
                    $args[$key] = &$value;
                }
            }

            unset($value);
        }

        return $args;
    }
}
