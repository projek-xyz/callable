<?php

declare(strict_types=1);

use Projek\Callable\DependencyException;
use Projek\Callable\Resolver;
use Projek\Callable\UnresolvableException;
use Psr\Container\ContainerExceptionInterface;
use Stubs\FakeContainer;
use Stubs\Invokable;
use Stubs\Registered;
use Stubs\Status;
use Stubs\TypedVariadic;
use Stubs\Unregistered;
use Stubs\Variadic;

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\given;
use function Kahlan\it;

describe(Resolver::class, function () {
    $container = new FakeContainer([
        Registered::class => fn () => new Registered,
    ]);

    // PSR-11 explicitly allows get() to fail with a *generic*
    // ContainerExceptionInterface (not NotFoundExceptionInterface) — for broken
    // factories, circular references, etc.
    $brokenContainer = fn () => new FakeContainer(
        failWith: new class('Simulated resolution failure') extends RuntimeException implements ContainerExceptionInterface {},
    );

    given('r', fn () => new Resolver($container));

    it('should throw error on non resolvable', function () {
        expect(fn () => $this->r->resolveCallable('foobar'))
            ->toThrow(UnresolvableException::invalidCallable('foobar'));
    });

    it('should resolve registered Class::method pair', function () use ($container) {
        expect($this->r->resolveCallable('Stubs\Registered::bar'))->toBe([$container->get(Registered::class), 'bar']);
    });

    it('should resolve unregistered Class::method pair', function () {
        expect($this->r->resolveCallable('Stubs\Unregistered::bar')[0])->toBeAnInstanceOf(Unregistered::class);
    });

    it('should throw error on invalid Class::method pair', function () {
        expect(fn () => $this->r->resolveCallable('Registered::bar'))
            ->toThrow(UnresolvableException::invalidCallable('Registered'));
    });

    it('should resolve registered [Class::class, method] pair', function () use ($container) {
        expect($this->r->resolveCallable([Registered::class, 'bar']))->toBe([$container->get(Registered::class), 'bar']);
    });

    it('should throw error on invalid [Class::class, method] pair', function () {
        expect(fn () => $this->r->resolveCallable(['Registered', 'bar']))
            ->toThrow(UnresolvableException::invalidCallable('Registered'));
    });

    it('should resolve registered [$obj, method] pair', function () use ($container) {
        $obj = $container->get(Registered::class);
        expect($this->r->resolveCallable([$obj, 'bar']))->toBe([$obj, 'bar']);
    });

    it('should pass a closure through untouched', function () {
        // Closures are the most frequently handed-over callable shape; they must be
        // returned by identity so that bound state, `use` variables and readonly
        // captures survive resolution.
        $closure = fn () => 'closure';

        expect($this->r->resolveCallable($closure))->toBe($closure);
    });

    it('should throw UnresolvableException for a non-callable scalar', function () {
        // resolveCallable() accepts untyped input, so scalars leaking in from config,
        // routes or request data are realistic. The exception must be ours and must
        // carry a message: an earlier implementation read an undefined $message for
        // integers/null/bools (none of its message branches applied), so callers got
        // a PHP warning plus an empty message instead of a diagnosis.
        $error = null;
        try {
            $this->r->resolveCallable(123);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(UnresolvableException::class);
        expect($error->getMessage())->not->toBe('');
    });

    it('should throw UnresolvableException for an empty array callable', function () {
        // An empty array must be reported as unresolvable without warnings: an
        // earlier implementation evaluated `is_string($callable[0])` first,
        // producing "Undefined array key 0" (Kahlan surfaces it as
        // PhpErrorException) before the domain exception could be thrown.
        $error = null;
        try {
            $this->r->resolveCallable([]);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(UnresolvableException::class);
    });

    it('should throw UnresolvableException for a pair array without a method', function () {
        // [$object] is a malformed pair: it has no method element, so none of the
        // string or pair message branches apply — same class of bug as the scalar
        // case, but through the array branch. Malformed input must always yield a
        // diagnosable exception instead of an empty message.
        $error = null;
        try {
            $this->r->resolveCallable([new stdClass]);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(UnresolvableException::class);
        expect($error->getMessage())->not->toBe('');
    });

    it('should throw UnresolvableException for an interface entry', function () {
        // Interfaces and traits are class-like names that can never be instantiated
        // (class_exists() is false for them). Resolving `[SomeInterface::class, 'm']`
        // must fail with the library's exception rather than leaking a container or
        // reflection error.
        expect(fn () => $this->r->resolveCallable([Stringable::class, 'jsonSerialize']))
            ->toThrow(UnresolvableException::invalidCallable('Stringable'));
    });

    it('should throw UnresolvableException for an enum entry', function () {
        // A bare enum class-string is not a callable: enums have no __invoke(),
        // so is_callable() is false and resolution fails before any container
        // lookup — invalidCallable() names the entry. The createInstance()
        // guard for non-instantiable classes is exercised separately, by the
        // [Status::class, 'label'] pair below.
        expect(fn () => $this->r->resolveCallable(Status::class))
            ->toThrow(UnresolvableException::invalidCallable(Status::class));
    });

    it('should throw UnresolvableException when the class is not instantiable', function () {
        // Enums are class_exists() but never instantiable. A pair naming a real
        // enum method passes the static-method check (label() is an instance
        // method) and reaches createInstance(), where isInstantiable() must
        // fire — otherwise ReflectionClass::newInstanceArgs() leaks a raw
        // ReflectionException instead of the library's exception.
        expect(fn () => $this->r->resolveCallable([Status::class, 'label']))
            ->toThrow(UnresolvableException::notInstantiable(Status::class));
    });

    it('should resolve a class-string of an invokable class', function () {
        // A class exposing __invoke() is a legitimate callable target. `Class::method`
        // strings are resolved to instances, but a bare invokable class-string is
        // rejected today because is_callable('Stubs\Invokable') is false for strings —
        // even though resolving it to an instance would make it callable. Decide:
        // auto-instantiate invokable class-strings, or document the rejection.
        $error = null;
        $result = null;
        try {
            $result = $this->r->resolveCallable(Invokable::class);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeNull();
        expect($result)->toBeAnInstanceOf(Invokable::class);
    });

    it('should throw UnresolvableException for an object without __invoke()', function () {
        // Objects are only callable through __invoke(); handing a plain object
        // to resolveCallable() must produce a message naming its class instead
        // of an object dump or an empty message.
        expect(fn () => $this->r->resolveCallable(new stdClass))
            ->toThrow(UnresolvableException::invalidCallable(new stdClass));
    });

    it('should resolve a static Class::method without instantiating the class', function () {
        // Static methods never need an object, yet resolveFromContainer() always
        // instantiates the class first. A pure static helper becomes unresolvable the
        // moment its constructor has dependencies the container cannot build (and
        // constructor side effects run needlessly). Static targets should be resolved
        // as [Class::class, $method] without construction.
        $error = null;
        $result = null;
        try {
            $result = $this->r->resolveCallable('Stubs\StaticOnly::make');
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeNull();
        expect(is_callable($result))->toBeTruthy();
    });

    it('should throw UnresolvableException when the method does not exist', function () {
        // Typos in 'Class::method' strings are the most common user error; the
        // resulting message must name the missing method so it can be fixed at a
        // glance (this is also the code path that surfaces __call-less misses).
        expect(fn () => $this->r->resolveCallable([Registered::class, 'nope']))
            ->toThrow(UnresolvableException::methodNotFound(Registered::class, 'nope'));
    });

    it('should name the method when a pair targets a private method', function () {
        // method_exists() reports private methods but is_callable() does not, so
        // such a pair survives both the static-method and __call() checks and
        // lands in invalidCallable()'s pair branch. The message must name the
        // method — a bare "not a callable" would send users hunting for wiring
        // problems when the real issue is visibility.
        expect(fn () => $this->r->resolveCallable([Registered::class, 'hidden']))
            ->toThrow(UnresolvableException::invalidCallable([Registered::class, 'hidden']));
    });

    it('should wrap generic container failures in UnresolvableException', function () use ($brokenContainer) {
        // PSR-11 lets get() throw a plain ContainerExceptionInterface, not only
        // NotFoundExceptionInterface. resolveCallable() documents @throws
        // UnresolvableException — raw container exceptions escaping break that
        // contract and force callers to catch errors from a foreign package.
        $resolver = new Resolver($brokenContainer());

        $error = null;
        try {
            $resolver->resolveCallable([Registered::class, 'bar']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(UnresolvableException::class);
        expect($error->getMessage())->toBe('Failed to resolve Stubs\Registered: Simulated resolution failure');
    });

    it('should ignore explicit arguments — binding them is Handler\'s job', function () use ($container) {
        // resolveParameter() no longer receives caller arguments at all: its only
        // caller (Handler) binds provided values before auto-wiring. Passing one
        // here must resolve from the container's instance — never $explicit.
        $param = (new ReflectionFunction(fn (Registered $r) => $r))->getParameters()[0];
        $explicit = new Registered;

        expect($this->r->resolveParameter($param, [$explicit]))
            ->toBe($container->get(Registered::class));
    });

    it('should resolve a type-hinted parameter from the container', function () {
        // Core contract of resolveParameter(): a class-typed parameter is fulfilled
        // by the container without the caller passing anything.
        $param = (new ReflectionFunction(fn (Registered $r) => $r))->getParameters()[0];

        expect($this->r->resolveParameter($param))->toBeAnInstanceOf(Registered::class);
    });

    it('should prefer the container entry over a default value when not provided', function () {
        // Documented precedence (AGENTS.md): defaults are consulted only when the
        // container cannot provide the type — keeps `= null` style defaults as a
        // last-resort fallback rather than a silent override of a configured service.
        $param = (new ReflectionFunction(fn (?Registered $r = null) => $r))->getParameters()[0];

        expect($this->r->resolveParameter($param))->toBeAnInstanceOf(Registered::class);
    });

    it('should fall back to the default value when the container lacks the type', function () {
        // Optional parameters must keep working against a container that has no
        // matching entry — the default (including `null`) is the safety net.
        $param = (new ReflectionFunction(fn (?Unregistered $u = null) => $u))->getParameters()[0];

        expect($this->r->resolveParameter($param))->toBeNull();
    });

    it('should fall back to an enum-case default value', function () {
        // Enum-typed parameters with enum-case defaults (`fn (Status $s =
        // Status::Draft)`) are a very common way to express defaults; the constant
        // expression must survive a failed container lookup.
        $param = (new ReflectionFunction(fn (Status $s = Status::Draft) => $s))->getParameters()[0];

        expect($this->r->resolveParameter($param))->toBe(Status::Draft);
    });

    it('should throw DependencyException naming the type and position', function () {
        // When a required dependency is missing this is the public error users see:
        // it must name the type, the parameter position, and chain the container
        // exception as previous for debugging. DependencyException has zero coverage
        // so far.
        $param = (new ReflectionFunction(fn (Unregistered $u) => $u))->getParameters()[0];

        expect(fn () => $this->r->resolveParameter($param))
            ->toThrow(new DependencyException('Stubs\Unregistered', 0));
    });

    it('should fall back to the parameter name when there is no class type', function () {
        // Untyped parameters cannot be looked up by type, so the container is queried
        // by bare parameter name. Pinning this down because it is the mechanism that
        // makes the name/type collisions below possible — and because it is currently
        // undocumented behaviour (AGENTS.md only mentions type-hints).
        $named = new FakeContainer([
            'registered' => fn () => 'injected-by-name',
        ]);
        $param = (new ReflectionFunction(fn ($registered) => $registered))->getParameters()[0];

        expect((new Resolver($named))->resolveParameter($param))->toBe('injected-by-name');
    });

    it('should not resolve a union-typed parameter from the container', function () {
        // Decision: union types are deliberately NOT auto-wired. Trying each
        // member invites ambiguity (both registered? which wins?) and silently
        // picking one hides the developer's intent — a required union parameter
        // must fail loudly instead, even when the container happens to hold one
        // of the members (here: Registered). Pass the value explicitly or use a
        // single class type to get container injection.
        $param = (new ReflectionFunction(fn (Registered|Unregistered $dep) => $dep))->getParameters()[0];

        $error = null;
        $result = null;
        try {
            $result = $this->r->resolveParameter($param);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(DependencyException::class);
        expect($result)->toBeNull();
    });

    it('should name the union type, not the parameter, in DependencyException', function () {
        // When union resolution fails the diagnostic must point at the type the
        // developer wrote, not at an arbitrary parameter name — "Dependency $dep at
        // position 0" sends users hunting for the wrong thing.
        $param = (new ReflectionFunction(fn (Registered|Unregistered $dep) => $dep))->getParameters()[0];

        expect(fn () => $this->r->resolveParameter($param))
            ->toThrow(new DependencyException('Stubs\Registered|Stubs\Unregistered', 0));
    });

    it('should refuse to resolve a by-reference parameter from the container', function () {
        // A container entry (or default) is a temporary: passing it to a
        // by-reference parameter lets the call succeed, but every write through
        // the reference is discarded once the call returns — an output-style
        // callable would silently produce nothing. Resolution must fail loudly
        // instead, telling the caller to pass the variable itself.
        $param = (new ReflectionFunction(function (&$out) {
            // .
        }))->getParameters()[0];

        expect(fn () => $this->r->resolveParameter($param))
            ->toThrow(new DependencyException('out', 0, 'by-reference parameter $out must be provided explicitly'));
    });

    it('should not let a container entry shadow a built-in default', function () {
        // For built-in types there is no class to resolve, so the resolver queries the
        // container by bare parameter name. Anything registered under that name
        // (here: 'count') overrides the developer's own default — and a wrong-typed
        // entry turns a working callable into a TypeError. For built-in-typed
        // parameters the default should win over name-based lookup.
        $counting = new FakeContainer([
            'count' => fn () => 7,
        ]);
        $param = (new ReflectionFunction(fn (int $count = 3) => $count))->getParameters()[0];

        expect((new Resolver($counting))->resolveParameter($param))->toBe(3);
    });

    it('should treat a variadic parameter as empty when no arguments are passed', function () {
        // Variadic parameters report isOptional() === true but have no default value,
        // so getDefaultValue() raises ReflectionException("Internal error: Failed to
        // retrieve the default value"). Calling a variadic callable with zero
        // arguments is completely normal and must yield an empty list, not an
        // internal reflection error.
        $param = (new ReflectionFunction(fn (...$args) => $args))->getParameters()[0];

        $error = null;
        try {
            $this->r->resolveParameter($param);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeNull();
    });

    it('should ignore explicit arguments for a variadic parameter (Handler splices them)', function () {
        // Collection moved to Handler's splice; the Resolver's variadic branch is
        // now a guard that returns [] so getDefaultValue() is never called on a
        // variadic parameter.
        $param = (new ReflectionFunction(fn (string $fixed, ...$args) => $args))->getParameters()[1];

        expect($this->r->resolveParameter($param, ['ignored-fixed', 1, 2, 'foo' => 'bar']))->toBe([]);
    });

    it('should build a class with a variadic constructor through createInstance()', function () {
        // createInstance() resolves constructor parameters through
        // resolveParameter(); a variadic has no default value, so without the
        // isVariadic() guard ReflectionException("Failed to retrieve the default
        // value") would leak instead of a working instance.
        $instance = $this->r->resolveCallable([Variadic::class, 'run'])[0];

        expect($instance)->toBeAnInstanceOf(Variadic::class);
        // newInstanceArgs() must receive NO argument for the variadic: feeding
        // the guard's [] back in would pack $args as [[]] — one phantom element
        // a native `new Variadic()` never produces.
        expect($instance->run())->toBe([]);
    });

    it('should construct a typed variadic constructor natively through createInstance()', function () {
        // The phantom [] is loud for typed variadics: as the single argument it
        // raises TypeError("... must be of type string, array given") — the
        // class constructs natively but not through us, even when the element
        // type is registered in the container.
        $instance = $this->r->resolveCallable([TypedVariadic::class, 'parts'])[0];

        expect($instance->parts())->toBe([]);
    });

    it('should wrap generic container failures in DependencyException', function () use ($brokenContainer) {
        // PSR-11 permits get() to throw a non-NotFound ContainerExceptionInterface
        // for resolution failures. resolveParameter() only catches
        // NotFoundExceptionInterface, so those escape raw even though the interface
        // documents @throws DependencyException — callers relying on the documented
        // exception type would crash instead.
        $resolver = new Resolver($brokenContainer());
        $param = (new ReflectionFunction(fn (stdClass $service) => $service))->getParameters()[0];

        $error = null;
        try {
            $resolver->resolveParameter($param);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(DependencyException::class);
    });

    it('should throw DependencyException when an untyped parameter is missing from the container', function () {
        // Untyped parameters resolve by bare name; when the container has no such
        // entry and there is no default, the failure must surface as
        // DependencyException naming the parameter — not as the container's own
        // NotFoundExceptionInterface escaping the interface's @throws contract.
        $param = (new ReflectionFunction(fn ($missing) => $missing))->getParameters()[0];

        expect(fn () => $this->r->resolveParameter($param))
            ->toThrow(new DependencyException('missing', 0));
    });

    it('should wrap generic container failures for untyped parameters', function () use ($brokenContainer) {
        // Same guarantee as the class-typed case, but through the bare-name
        // lookup: a broken factory must still surface as DependencyException
        // rather than the container's foreign exception type.
        $resolver = new Resolver($brokenContainer());
        $param = (new ReflectionFunction(fn ($service) => $service))->getParameters()[0];

        $error = null;
        try {
            $resolver->resolveParameter($param);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(DependencyException::class);
        expect($error->getMessage())->toBe('Dependency service at position 0 is not resolvable');
    });
});
