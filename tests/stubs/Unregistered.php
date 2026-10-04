<?php

namespace Stubs;

class Unregistered
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
