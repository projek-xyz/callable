<?php

declare(strict_types=1);

namespace Stubs;

final class StaticOnly
{
    /**
     * The constructor dependency makes instantiation fail when the container
     * cannot build it — even though make() below never needs an instance.
     */
    public function __construct(
        private Unregistered $unregistered
    ) {
        // .
    }

    public static function make(): string
    {
        return 'made';
    }
}
