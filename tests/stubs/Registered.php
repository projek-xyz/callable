<?php

declare(strict_types=1);

namespace Stubs;

final class Registered
{
    public function bar()
    {
        // .
    }

    /**
     * method_exists() reports private methods, but is_callable() does not —
     * a pair naming it must fail with an error that points at the visibility
     * problem.
     */
    private function hidden()
    {
        // .
    }
}
