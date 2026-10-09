<?php

declare(strict_types=1);

namespace Stubs;

final class Constructed
{
    public static int $built = 0;

    public function __construct(
        public Registered $dependency,
        public int $count,
        public string $label = 'label',
    ) {
        self::$built++;
    }
}
