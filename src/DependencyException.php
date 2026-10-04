<?php

namespace Projek\Callable;

use RuntimeException;
use Throwable;

class DependencyException extends RuntimeException
{
    public function __construct(
        public readonly string $name,
        public readonly int $position,
        ?Throwable $previous = null,
    ) {
        $message = sprintf('Dependency %s at position %d is not resolvable', $name, $position);

        parent::__construct($message, 0, $previous);
    }
}
