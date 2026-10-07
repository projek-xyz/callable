<?php

declare(strict_types=1);

use Projek\Callable\ResolverExceptionInterface;
use Projek\Callable\UnresolvableCallableException;
use Projek\Callable\UnresolvableParameterException;

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\it;

describe(ResolverExceptionInterface::class, function () {
    it('is implemented by UnresolvableCallableException', function () {
        $exception = UnresolvableCallableException::invalidCallable('not-a-callable');

        expect($exception)->toBeAnInstanceOf(ResolverExceptionInterface::class);
    });

    it('is implemented by UnresolvableParameterException', function () {
        $param = (new ReflectionFunction(fn (string $dsn) => $dsn))->getParameters()[0];

        expect(new UnresolvableParameterException($param))->toBeAnInstanceOf(ResolverExceptionInterface::class);
    });
});
