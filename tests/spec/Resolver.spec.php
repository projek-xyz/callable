<?php

declare(strict_types=1);

use Projek\Callable\Resolver;
use Projek\Callable\UnresolvableException;
use Projek\Container;
use Stubs\Registered;
use Stubs\Unregistered;

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\given;
use function Kahlan\it;

describe(Resolver::class, function () {
    $container = new Container([
        Registered::class => fn () => new Registered,
    ]);

    given('r', fn () => new Resolver($container));

    it('Should throw error on non resolvable', function () {
        expect(fn () => $this->r->resolveCallable('foobar'))->toThrow(new UnresolvableException('foobar'));
    });

    it('Should resolve registered Class::method pair', function () use ($container) {
        expect($this->r->resolveCallable('Stubs\Registered::bar'))->toBe([$container->get(Registered::class), 'bar']);
    });

    it('Should resolve unregistered Class::method pair', function () {
        expect($this->r->resolveCallable('Stubs\Unregistered::bar')[0])->toBeAnInstanceOf(Unregistered::class);
    });

    it('Should throw error on invalid Class::method pair', function () {
        expect(fn () => $this->r->resolveCallable('Registered::bar'))->toThrow(new UnresolvableException('Registered'));
    });

    it('Should resolve registered [Class::class, method] pair', function () use ($container) {
        expect($this->r->resolveCallable([Registered::class, 'bar']))->toBe([$container->get(Registered::class), 'bar']);
    });

    it('Should throw error on invalid [Class::class, method] pair', function () {
        expect(fn () => $this->r->resolveCallable(['Registered', 'bar']))->toThrow(new UnresolvableException('Registered'));
    });

    it('Should resolve registered [$obj, method] pair', function () use ($container) {
        $obj = $container->get(Registered::class);
        expect($this->r->resolveCallable([$obj, 'bar']))->toBe([$obj, 'bar']);
    });
});
