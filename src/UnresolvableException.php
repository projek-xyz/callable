<?php

namespace Projek\Callable;

use InvalidArgumentException;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

class UnresolvableException extends InvalidArgumentException
{
    public function __construct($callable, ?Throwable $previous = null)
    {
        $callable = is_object($callable) ? get_class($callable) : $callable;

        if (is_string($callable)) {
            $message = sprintf('Instance of %s is not a resolvable', $callable);
        } elseif (is_array($callable) && isset($callable[0], $callable[1])) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : $callable[0];
            $message = sprintf('%s::%s() is not a resolvable', $class, $callable[1]);
        } elseif ($previous instanceof NotFoundExceptionInterface) {
            $message = sprintf('%s invalid container entry', $callable);
        }

        parent::__construct($message, 0, $previous);
    }
}
