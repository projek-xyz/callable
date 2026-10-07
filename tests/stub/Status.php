<?php

declare(strict_types=1);

namespace Stubs;

/**
 * Enums are class_exists() but never instantiable; they commonly appear as
 * type-hints with enum-case defaults, e.g. `fn (Status $s = Status::Draft)`.
 */
enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';

    /**
     * Instance methods on enums make [Status::class, 'label'] a realistic
     * pair: the method exists, but ReflectionClass::newInstanceArgs() can
     * never build an enum instance.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
