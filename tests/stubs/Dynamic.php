<?php

namespace Stubs;

class Dynamic
{
    /**
     * Magic methods make every method name pass is_callable(), mirroring real-world
     * proxies and __call-based services — the Resolver/Handler must not assume that
     * a "callable" method also satisfies method_exists().
     */
    public function __call(string $name, array $arguments)
    {
        return $name;
    }
}
