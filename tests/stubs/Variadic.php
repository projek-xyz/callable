<?php

namespace Stubs;

class Variadic
{
    private array $args;

    public function __construct(...$args)
    {
        $this->args = $args;
    }

    public function run()
    {
        return $this->args;
    }
}
