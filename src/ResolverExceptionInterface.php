<?php

declare(strict_types=1);

namespace Projek\Callable;

use Throwable;

/**
 * Single catch point for any failure raised while resolving a callable, its arguments, or a
 * parameter.
 *
 * Implemented by `UnresolvableCallableException` and `UnresolvableParameterException`, so one
 * catch handles every resolution failure. It deliberately does not extend PSR-11's
 * `ContainerExceptionInterface`: container failures beyond a missing entry propagate untouched
 * and must stay distinguishable from this library's own failures.
 *
 * @see UnresolvableCallableException
 * @see UnresolvableParameterException
 */
interface ResolverExceptionInterface extends Throwable
{
    // .
}
