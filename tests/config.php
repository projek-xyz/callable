<?php

declare(strict_types=1);

/**
 * @link https://kahlan.github.io/docs/config-file.html
 */

use Kahlan\Filter\Filters;
use Kahlan\Reporter\Coverage\Exporter;
use Kahlan\Scope;

/** @var Kahlan\Cli\Kahlan $this */
$cli = $this->commandLine();
$cli->option('coverage', 'default', 3);
$cli->option('spec', 'default', ['tests/spec']);
$cli->option('lcov', 'default', 'tests/lcov.info');

// Load the library verbatim instead of through Kahlan's JIT patcher. The
// Monkey patcher rewrites every global function call — including
// \call_user_func_array() — into a variable call, and PHP does not propagate
// strict_types to a callee dispatched through a runtime-resolved call: the
// engine's TypeError on a mismatched argument would silently coerce instead.
// No spec uses Kahlan's patching features (allow/spy/toReceive), so excluding
// the code under test only restores production semantics.
$cli->option('exclude', 'default', ['Projek\\Callable\\']);

Filters::apply($this, 'reporting', function ($next) {
    /** @var Kahlan\Cli\Kahlan $this */
    if (! $reporter = $this->reporters()->get('coverage')) {
        return;
    }

    $lcov_file = getenv('LCOV_REPORT') ?: $this->reporters()->get('lcov');

    if ($lcov_file) {
        Exporter\Lcov::write([
            'collector' => $reporter,
            'file' => $lcov_file,
        ]);
    }

    return $next();
});

Filters::apply($this, 'run', function ($next) {
    /** @var Scope $scope */
    $scope = $this->suite()->root()->scope(); // The top most describe scope.

    $scope->stubsDir = function (string ...$paths) {
        array_unshift($paths, 'stubs');

        return __DIR__.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $paths);
    };

    return $next();
});
