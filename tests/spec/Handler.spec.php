<?php

declare(strict_types=1);

use Projek\Callable\Handler;

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\it;

describe(Handler::class, function () {
    it('Should be an instance of', function () {
        expect(class_exists(Handler::class))->toBeTruthy();
    });
});
