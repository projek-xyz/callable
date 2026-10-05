<?php

namespace Stubs;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * PSR-11 "entry missing" error for FakeContainer (PSR-11 ships interfaces only).
 * Its message is never asserted — specs pin the exception *types* and the
 * library's own messages, not the container's wording.
 */
final class NotFound extends RuntimeException implements NotFoundExceptionInterface
{
    public function __construct(string $id)
    {
        parent::__construct(\sprintf('Entry "%s" is not found.', $id));
    }
}
