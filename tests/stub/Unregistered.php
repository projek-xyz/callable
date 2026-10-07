<?php

declare(strict_types=1);

namespace Stubs;

final class Unregistered
{
    public function __construct(
        public Registered $registered
    ) {
        // .
    }

    public function bar()
    {
        // .
    }
}
