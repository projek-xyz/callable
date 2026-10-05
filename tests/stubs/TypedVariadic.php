<?php

namespace Stubs;

final class TypedVariadic
{
    private array $parts;

    public function __construct(string ...$parts)
    {
        $this->parts = $parts;
    }

    public function parts()
    {
        return $this->parts;
    }
}
