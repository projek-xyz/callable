<?php

declare(strict_types=1);

namespace Stubs;

final class Variadic
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
