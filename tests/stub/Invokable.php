<?php

declare(strict_types=1);

namespace Stubs;

final class Invokable
{
    /**
     * Invokable objects are a common callable shape (middleware, handlers); the
     * optional parameter also exercises default-value resolution on __invoke().
     */
    public function __invoke(string $suffix = ''): string
    {
        return 'invoked'.$suffix;
    }
}
