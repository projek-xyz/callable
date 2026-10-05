<?php

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
