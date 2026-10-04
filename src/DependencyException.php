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
        ?string $detail = null,
    ) {
        $message = sprintf('Dependency %s at position %d is not resolvable', $name, $position);

        // Optional detail explains WHY the dependency cannot be resolved (e.g. a
        // by-reference parameter that must be provided explicitly) without
        // changing the base message format callers may rely on.
        if ($detail !== null) {
            $message .= ': '.$detail;
        }

        parent::__construct($message, 0, $previous);
    }
}
