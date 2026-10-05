<?php

declare(strict_types=1);

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\it;

describe('Source conventions', function () {
    it('should declare strict_types in every source file except exceptions and interfaces', function () {
        // strict_types governs calls made *from* a file, so it matters most in the
        // classes carrying real logic (Handler, Resolver) — there it stops silent
        // scalar coercion inside the library's own calls. Exception classes only
        // build messages and call parent::__construct, and ResolverInterface is pure
        // declaration (no calls at all, making the flag a no-op there), so per
        // decision we exempt *Exception.php and *Interface.php rather than force the
        // declaration where it cannot change behavior. The pattern-based exemption
        // keeps future exceptions/interfaces automatically covered.
        //
        // Note: strict_types does NOT affect what callers pass *into* this library —
        // argument coercion at a call site is decided by the caller's file mode.
        $missing = [];
        foreach (glob(__DIR__.'/../../src/*.php') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('/(Exception|Interface)\.php$/', $name)) {
                continue;
            }

            if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $missing[] = $name;
            }
        }

        expect($missing)->toBe([]);
    });
});
