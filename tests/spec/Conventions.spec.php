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

describe('Spec conventions', function () {
    it('should assert error paths with toThrow() instead of hand-rolled catch blocks', function () {
        // An error-path spec that catches Throwable itself can swallow the very
        // failure it exists to make — and Kahlan reports zero-expectation specs
        // as Pending with exit 0, so a regression here sails through green CI as
        // a dead spec. Forbid catching Throwable outright in tests/spec/ so
        // every error-path spec keeps its toThrow() pin and the suite's
        // "0 Pending" guarantee holds. The needle is split across literals and
        // file contents are read with backslashes stripped, so the checker
        // matches both the plain and the fully-qualified spelling — and can
        // never match itself.
        $violations = [];
        foreach (glob(__DIR__.'/*.spec.php') ?: [] as $file) {
            $contents = str_replace('\\', '', (string) file_get_contents($file));

            if (str_contains($contents, 'catch ('.'Throwable')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBe([]);
    });
});
