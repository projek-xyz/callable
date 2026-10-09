<?php

declare(strict_types=1);

use Projek\Callable\Resolver;
use Projek\Callable\UnresolvableCallableException;
use Projek\Callable\UnresolvableParameterException;
use Psr\Container\ContainerExceptionInterface;
use Stubs\Constructed;
use Stubs\FakeContainer;
use Stubs\Invokable;
use Stubs\Registered;
use Stubs\StaticOnly;
use Stubs\Status;
use Stubs\TypedVariadic;
use Stubs\Unregistered;
use Stubs\Variadic;

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\it;

describe(Resolver::class, function () {
    $container = new FakeContainer([
        Registered::class => fn () => new Registered,
    ]);

    // PSR-11 explicitly allows get() to fail with a *generic*
    // ContainerExceptionInterface (not NotFoundExceptionInterface) — for broken
    // factories, circular references, etc. The instance is hoisted so specs can
    // assert the exact object escapes unwrapped (Kahlan's toThrow matches the
    // exception's concrete class).
    $exploding = new class('Simulated resolution failure') extends RuntimeException implements ContainerExceptionInterface {};
    $brokenContainer = fn () => new FakeContainer(failWith: $exploding);

    it('should throw error on non resolvable', function () use ($container) {
        // A function-name string that names no existing function must come back
        // as the library's own diagnosis — not be handed back as a callable that
        // only explodes at invocation time, deep in the caller's code.
        expect(fn () => (new Resolver($container))->resolveCallable('foobar'))
            ->toThrow(UnresolvableCallableException::invalidCallable('foobar'));
    });

    it('should resolve registered Class::method pair', function () use ($container) {
        // The headline shorthand end-to-end: a namespaced 'Class::method' string
        // resolves through the container and comes back as [$instance, 'bar'] —
        // the memoized instance itself, identity asserted, not a fresh copy.
        expect((new Resolver($container))->resolveCallable('Stubs\Registered::bar'))->toBe([$container->get(Registered::class), 'bar']);
    });

    it('should resolve unregistered Class::method pair', function () use ($container) {
        // Registration is optional: a class the container has never heard of is
        // still resolved — construction falls back to reflection, so the
        // shorthand works with zero wiring.
        expect((new Resolver($container))->resolveCallable('Stubs\Unregistered::bar')[0])->toBeAnInstanceOf(Unregistered::class);
    });

    it('should throw error on invalid Class::method pair', function () use ($container) {
        // A shorthand whose class does not exist (bare 'Registered' without its
        // namespace — a classic typo) must be diagnosed before invocation, with
        // the message naming the class so the mistake is obvious at a glance.
        expect(fn () => (new Resolver($container))->resolveCallable('Registered::bar'))
            ->toThrow(UnresolvableCallableException::invalidCallable('Registered'));
    });

    it('should resolve registered [Class::class, method] pair', function () use ($container) {
        // The array form must behave exactly like the string shorthand — same
        // container resolution, same [$instance, 'bar'] result — for callers
        // who build pairs programmatically instead of concatenating strings.
        expect((new Resolver($container))->resolveCallable([Registered::class, 'bar']))->toBe([$container->get(Registered::class), 'bar']);
    });

    it('should throw error on invalid [Class::class, method] pair', function () use ($container) {
        // Same diagnosis as the string shorthand when the class-string is not a
        // real class: both input shapes funnel into one unresolvable-callable
        // failure instead of reaching instantiation.
        expect(fn () => (new Resolver($container))->resolveCallable(['Registered', 'bar']))
            ->toThrow(UnresolvableCallableException::invalidCallable('Registered'));
    });

    it('should resolve registered [$obj, method] pair', function () use ($container) {
        // An already-resolved object bypasses the container entirely: the pair
        // passes through untouched — same instance, same method — so no
        // re-resolution or copy can break identity comparisons downstream.
        $obj = $container->get(Registered::class);
        expect((new Resolver($container))->resolveCallable([$obj, 'bar']))->toBe([$obj, 'bar']);
    });

    it('should pass a closure through untouched', function () use ($container) {
        // Closures are the most frequently handed-over callable shape; they must be
        // returned by identity so that bound state, `use` variables and readonly
        // captures survive resolution.
        $closure = fn () => 'closure';

        expect((new Resolver($container))->resolveCallable($closure))->toBe($closure);
    });

    it('should throw UnresolvableCallableException for a non-callable scalar', function () use ($container) {
        // resolveCallable() accepts untyped input, so scalars leaking in from config,
        // routes or request data are realistic. The exception must be ours and must
        // carry a message: an earlier implementation read an undefined $message for
        // integers/null/bools (none of its message branches applied), so callers got
        // a PHP warning plus an empty message instead of a diagnosis.
        expect(fn () => (new Resolver($container))->resolveCallable(123))
            ->toThrow(UnresolvableCallableException::invalidCallable(123));
    });

    it('should throw UnresolvableCallableException for an empty array callable', function () use ($container) {
        // An empty array must be reported as unresolvable without warnings: an
        // earlier implementation evaluated `is_string($callable[0])` first,
        // producing "Undefined array key 0" (Kahlan surfaces it as
        // PhpErrorException) before the domain exception could be thrown.
        expect(fn () => (new Resolver($container))->resolveCallable([]))
            ->toThrow(UnresolvableCallableException::invalidCallable([]));
    });

    it('should throw UnresolvableCallableException for a pair array without a method', function () use ($container) {
        // [$object] is a malformed pair: it has no method element, so none of the
        // string or pair message branches apply — same class of bug as the scalar
        // case, but through the array branch. Malformed input must always yield a
        // diagnosable exception instead of an empty message.
        expect(fn () => (new Resolver($container))->resolveCallable([new stdClass]))
            ->toThrow(UnresolvableCallableException::invalidCallable([new stdClass]));
    });

    it('should throw UnresolvableCallableException for an interface entry', function () use ($container) {
        // Interfaces and traits are class-like names that can never be instantiated
        // (class_exists() is false for them). Resolving `[SomeInterface::class, 'm']`
        // must fail with the library's exception rather than leaking a container or
        // reflection error.
        expect(fn () => (new Resolver($container))->resolveCallable([Stringable::class, 'jsonSerialize']))
            ->toThrow(UnresolvableCallableException::invalidCallable('Stringable'));
    });

    it('should throw UnresolvableCallableException for an enum entry', function () use ($container) {
        // A bare enum class-string is not a callable: enums have no __invoke(),
        // so is_callable() is false and resolution fails before any container
        // lookup — invalidCallable() names the entry. The resolveInstance()
        // guard for non-instantiable classes is exercised separately, by the
        // [Status::class, 'label'] pair below.
        expect(fn () => (new Resolver($container))->resolveCallable(Status::class))
            ->toThrow(UnresolvableCallableException::invalidCallable(Status::class));
    });

    it('should throw UnresolvableCallableException when the class is not instantiable', function () use ($container) {
        // Enums are class_exists() but never instantiable. A pair naming a real
        // enum method passes the static-method check (label() is an instance
        // method) and reaches resolveInstance(), where isInstantiable() must
        // fire — otherwise ReflectionClass::newInstanceArgs() leaks a raw
        // ReflectionException instead of the library's exception.
        expect(fn () => (new Resolver($container))->resolveCallable([Status::class, 'label']))
            ->toThrow(UnresolvableCallableException::notInstantiable(Status::class));
    });

    it('should resolve a class-string of an invokable class', function () use ($container) {
        // A class exposing __invoke() is a legitimate callable target, but
        // is_callable('Stubs\Invokable') is false for a bare class-string — an
        // earlier implementation let that decide and rejected the string even
        // though resolving it to an instance makes it callable. Pin the
        // auto-instantiation so invokable class-strings keep working.
        expect((new Resolver($container))->resolveCallable(Invokable::class))->toBeAnInstanceOf(Invokable::class);
    });

    it('should throw UnresolvableCallableException for an object without __invoke()', function () use ($container) {
        // Objects are only callable through __invoke(); handing a plain object
        // to resolveCallable() must produce a message naming its class instead
        // of an object dump or an empty message.
        expect(fn () => (new Resolver($container))->resolveCallable(new stdClass))
            ->toThrow(UnresolvableCallableException::invalidCallable(new stdClass));
    });

    it('should resolve a static Class::method without instantiating the class', function () use ($container) {
        // Static methods never need an object, yet resolution used to instantiate
        // the class first: a pure static helper became unresolvable the moment its
        // constructor had dependencies the container could not build (and
        // constructor side effects ran needlessly). Pin the short-circuit — static
        // targets resolve as [Class::class, $method] with no construction.
        $result = (new Resolver($container))->resolveCallable('Stubs\StaticOnly::make');

        expect(is_callable($result))->toBeTruthy();
    });

    it('should throw UnresolvableCallableException when the method does not exist', function () use ($container) {
        // Typos in 'Class::method' strings are the most common user error; the
        // resulting message must name the missing method so it can be fixed at a
        // glance (this is also the code path that surfaces __call-less misses).
        expect(fn () => (new Resolver($container))->resolveCallable([Registered::class, 'nope']))
            ->toThrow(UnresolvableCallableException::methodNotFound(Registered::class, 'nope'));
    });

    it('should name the method when a pair targets a private method', function () use ($container) {
        // method_exists() reports private methods but is_callable() does not, so
        // such a pair survives both the static-method and __call() checks and
        // lands in invalidCallable()'s pair branch. The message must name the
        // method — a bare "not a callable" would send users hunting for wiring
        // problems when the real issue is visibility.
        expect(fn () => (new Resolver($container))->resolveCallable([Registered::class, 'hidden']))
            ->toThrow(UnresolvableCallableException::invalidCallable([Registered::class, 'hidden']));
    });

    it('should let a generic container failure propagate from resolveCallable', function () use ($brokenContainer, $exploding) {
        // Same contract as resolveParameter: only NotFoundExceptionInterface is
        // ours to handle — a missing entry falls back to reflection
        // instantiation. A generic ContainerExceptionInterface (broken factory,
        // circular reference) is the orchestrator's failure and escapes as the
        // exact instance the container threw, so third parties handle their
        // own container.
        $resolver = new Resolver($brokenContainer());

        expect(fn () => $resolver->resolveCallable([Registered::class, 'bar']))
            ->toThrow($exploding);
    });

    it('should ignore explicit arguments — binding them is Handler\'s job', function () use ($container) {
        // resolveParameter() no longer receives caller arguments at all: its only
        // caller (Handler) binds provided values before auto-wiring. Passing one
        // here must resolve from the container's instance — never $explicit.
        $param = (new ReflectionFunction(fn (Registered $r) => $r))->getParameters()[0];
        $explicit = new Registered;

        expect((new Resolver($container))->resolveParameter($param, [$explicit]))
            ->toBe($container->get(Registered::class));
    });

    it('should resolve a type-hinted parameter from the container', function () use ($container) {
        // Core contract of resolveParameter(): a class-typed parameter is fulfilled
        // by the container without the caller passing anything.
        $param = (new ReflectionFunction(fn (Registered $r) => $r))->getParameters()[0];

        expect((new Resolver($container))->resolveParameter($param))->toBeAnInstanceOf(Registered::class);
    });

    it('should prefer the container entry over a default value when not provided', function () use ($container) {
        // Documented precedence (AGENTS.md): defaults are consulted only when the
        // container cannot provide the type — keeps `= null` style defaults as a
        // last-resort fallback rather than a silent override of a configured service.
        $param = (new ReflectionFunction(fn (?Registered $r = null) => $r))->getParameters()[0];

        expect((new Resolver($container))->resolveParameter($param))->toBeAnInstanceOf(Registered::class);
    });

    it('should fall back to the default value when the container lacks the type', function () use ($container) {
        // Optional parameters must keep working against a container that has no
        // matching entry — the default (including `null`) is the safety net.
        $param = (new ReflectionFunction(fn (?Unregistered $u = null) => $u))->getParameters()[0];

        expect((new Resolver($container))->resolveParameter($param))->toBeNull();
    });

    it('should fall back to an enum-case default value', function () use ($container) {
        // Enum-typed parameters with enum-case defaults (`fn (Status $s =
        // Status::Draft)`) are a very common way to express defaults; the constant
        // expression must survive a failed container lookup.
        $param = (new ReflectionFunction(fn (Status $s = Status::Draft) => $s))->getParameters()[0];

        expect((new Resolver($container))->resolveParameter($param))->toBe(Status::Draft);
    });

    it('should throw UnresolvableParameterException naming the type and position', function () use ($container) {
        // When a required dependency is missing this is the public error users see:
        // it must name the type, the parameter position, and chain the container
        // exception as previous for debugging.
        $param = (new ReflectionFunction(fn (Unregistered $u) => $u))->getParameters()[0];

        expect(fn () => (new Resolver($container))->resolveParameter($param))
            ->toThrow(new UnresolvableParameterException($param, 'Stubs\Unregistered'));
    });

    it('should label method parameters Class::method like native errors', function () use ($container) {
        // Native TypeErrors lead with the declaring callable
        // ("A::b(): Argument #1 ..."): for a method's parameter the label must
        // carry the class, not just the bare method name, or the message points
        // at the wrong thing. StaticOnly's constructor dependency cannot be
        // auto-wired, making its first parameter fail resolution.
        $param = (new ReflectionMethod(StaticOnly::class, '__construct'))->getParameters()[0];

        expect(fn () => (new Resolver($container))->resolveParameter($param))
            ->toThrow(new UnresolvableParameterException($param, 'Stubs\Unregistered'));
    });

    it('should fall back to the parameter name when there is no class type', function () {
        // Untyped parameters cannot be looked up by type, so the container is queried
        // by bare parameter name. Pinning this down because it is the mechanism that
        // makes the name/type collisions below possible.
        $named = new FakeContainer(['registered' => fn () => 'injected-by-name']);
        $param = (new ReflectionFunction(fn ($registered) => $registered))->getParameters()[0];

        expect((new Resolver($named))->resolveParameter($param))->toBe('injected-by-name');
    });

    it('should not resolve a union-typed parameter from the container', function () use ($container) {
        // Decision: union types are deliberately NOT auto-wired. Trying each
        // member invites ambiguity (both registered? which wins?) and silently
        // picking one hides the developer's intent — a required union parameter
        // must fail loudly instead, even when the container happens to hold one
        // of the members (here: Registered). Pass the value explicitly or use a
        // single class type to get container injection. The failure must also
        // name what was declared: a bare "($dep) is not resolvable" says what
        // failed but not what was written, so the detail suffix carries the union.
        $param = (new ReflectionFunction(fn (Registered|Unregistered $dep) => $dep))->getParameters()[0];

        expect(fn () => (new Resolver($container))->resolveParameter($param))
            ->toThrow(new UnresolvableParameterException($param, 'Stubs\Registered|Stubs\Unregistered'));
    });

    it('should refuse to resolve a by-reference parameter from the container', function () use ($container) {
        // A container entry (or default) is a temporary: passing it to a
        // by-reference parameter lets the call succeed, but every write through
        // the reference is discarded once the call returns — an output-style
        // callable would silently produce nothing. Resolution must fail loudly
        // instead, telling the caller to pass the variable itself.
        $param = (new ReflectionFunction(function (&$out) {
            // .
        }))->getParameters()[0];

        expect(fn () => (new Resolver($container))->resolveParameter($param))
            ->toThrow(new UnresolvableParameterException($param, 'by-reference parameter must be provided explicitly'));
    });

    it('should not let a container entry shadow a built-in default', function () {
        // For built-in types there is no class to resolve, so the resolver queries the
        // container by bare parameter name — which used to let anything registered
        // under that name (here: 'count') override the developer's own default, a
        // wrong-typed entry turning a working callable into a TypeError. For
        // built-in-typed parameters the default now wins over name-based lookup.
        $counting = new FakeContainer(['count' => fn () => 7]);
        $param = (new ReflectionFunction(fn (int $count = 3) => $count))->getParameters()[0];

        expect((new Resolver($counting))->resolveParameter($param))->toBe(3);
    });

    it('should treat a variadic parameter as empty when no arguments are passed', function () use ($container) {
        // Variadic parameters report isOptional() === true but have no default value,
        // so getDefaultValue() raises ReflectionException("Internal error: Failed to
        // retrieve the default value"). Calling a variadic callable with zero
        // arguments is completely normal and must yield an empty list, not an
        // internal reflection error.
        $param = (new ReflectionFunction(fn (...$args) => $args))->getParameters()[0];

        expect((new Resolver($container))->resolveParameter($param))->toBe([]);
    });

    it('should ignore explicit arguments for a variadic parameter (Handler splices them)', function () use ($container) {
        // Collection moved to Handler's splice; the Resolver's variadic branch is
        // now a guard that returns [] so getDefaultValue() is never called on a
        // variadic parameter.
        $param = (new ReflectionFunction(fn (string $fixed, ...$args) => $args))->getParameters()[1];

        expect((new Resolver($container))->resolveParameter($param, ['ignored-fixed', 1, 2, 'foo' => 'bar']))->toBe([]);
    });

    it('should bind provided arguments and auto-wire the rest through resolveArguments', function () use ($container) {
        // The full argument list is the resolver's job now: a named key binds
        // its parameter while the unprovided class-typed sibling auto-wires
        // from the container — delegation to resolveParameter() proven in one
        // pass, without duplicating the binding matrix pinned in Handler.spec.
        $parameters = (new ReflectionFunction(fn (Registered $r, int $n) => $r))->getParameters();

        expect((new Resolver($container))->resolveArguments($parameters, ['n' => 7]))
            ->toBe([$container->get(Registered::class), 7]);
    });

    it('should reject a positional argument that follows a named one in resolveArguments', function () use ($container) {
        // Native call_user_func_array() throws this Error for the key order;
        // binding lives in resolveArguments() now, so the ordering rule must
        // fire here — before any auto-wiring runs.
        $parameters = (new ReflectionFunction(fn ($a, $b) => $a))->getParameters();

        expect(fn () => (new Resolver($container))->resolveArguments($parameters, ['b' => 'y', 0 => 'x']))
            ->toThrow(new Error('Cannot use positional argument after named argument'));
    });

    it('should preserve references into the provided arguments in resolveArguments', function () use ($container) {
        // Binding now runs in another object's frame — the reference chain
        // into the provided array must survive the method boundary, or
        // by-reference callables silently stop mutating the caller's variables.
        $reference = 'original';
        $parameters = (new ReflectionFunction(function (&$arg) {
            // .
        }))->getParameters();

        $args = (new Resolver($container))->resolveArguments($parameters, [&$reference]);
        $args[0] = 'changed';

        expect($reference)->toBe('changed');
    });

    it('should splice leftover arguments into a trailing variadic in resolveArguments', function () use ($container) {
        // Direct resolver-level pin of the variadic path (the end-to-end
        // matrix runs through Handler): leftover positional keys renumber
        // from zero, unmatched named arguments keep their string keys — both
        // splice arms in one assertion.
        $parameters = (new ReflectionFunction(function ($fixed, ...$rest) {
            // .
        }))->getParameters();

        expect((new Resolver($container))->resolveArguments($parameters, [1, 2, 'tail' => 'x']))
            ->toBe([1, 2, 'tail' => 'x']);
    });

    it('should construct exactly once per build', function () use ($container) {
        // Regression guard (container PR finding): construction must happen
        // exactly once inside resolveInstance() — no per-build re-entry.
        Constructed::$built = 0;

        $built = (new Resolver($container))->resolveInstance(Constructed::class, ['count' => 5]);

        expect($built)->toBeAnInstanceOf(Constructed::class);
        expect(Constructed::$built)->toBe(1);
    });

    it('should auto-wire the constructor arguments it was not given', function () use ($container) {
        // Explicit $args bind by name; the untouched class-typed sibling
        // auto-wires from the container and the default stays put.
        Constructed::$built = 0;

        $built = (new Resolver($container))->resolveInstance(Constructed::class, ['count' => 7]);

        expect($built->dependency)->toBe($container->get(Registered::class));
        expect($built->count)->toBe(7);
        expect($built->label)->toBe('label');
        expect(Constructed::$built)->toBe(1);
    });

    it('should surface UnresolvableParameterException for a missing required constructor parameter', function () use ($container) {
        // Library contract: a missing required parameter is auto-wired — when
        // nothing can supply it, the diagnosable library exception replaces
        // the engine's bare ArgumentCountError. Construction never starts.
        Constructed::$built = 0;
        $param = (new ReflectionClass(Constructed::class))->getConstructor()->getParameters()[1];

        expect(fn () => (new Resolver($container))->resolveInstance(Constructed::class))
            ->toThrow(new UnresolvableParameterException($param));
        expect(Constructed::$built)->toBe(0);
    });

    it('should ignore extra positional arguments for a class without a constructor', function () use ($container) {
        // Native parity: PHP 8.4 allows extra constructor arguments for a
        // class without a constructor — pin it here so a stricter engine
        // behaviour surfaces as a spec failure, not a runtime surprise.
        $instance = (new Resolver($container))->resolveInstance(Registered::class, ['extra']);

        expect($instance)->toBeAnInstanceOf(Registered::class);
    });

    it('should pack provided arguments into a variadic constructor', function () use ($container) {
        // The old []-guard handed a variadic constructor an empty list and
        // dropped the caller's arguments; provided arguments must reach the
        // constructor and pack exactly like a native call.
        $instance = (new Resolver($container))->resolveInstance(Variadic::class, ['a', 'b']);

        expect($instance->run())->toBe(['a', 'b']);
    });

    it('should reject an unknown named constructor argument before building', function () use ($container) {
        // Native named-argument rule: an unknown name is an engine Error —
        // raised during binding, before any constructor side effect runs.
        Constructed::$built = 0;

        expect(fn () => (new Resolver($container))->resolveInstance(Constructed::class, ['nope' => 1]))
            ->toThrow(new Error('Unknown named parameter $nope'));
        expect(Constructed::$built)->toBe(0);
    });

    it('should build a class with a variadic constructor through resolveInstance()', function () use ($container) {
        // resolveInstance() resolves constructor parameters through
        // resolveParameter(); a variadic has no default value, so without the
        // isVariadic() guard ReflectionException("Failed to retrieve the default
        // value") would leak instead of a working instance.
        $instance = (new Resolver($container))->resolveCallable([Variadic::class, 'run'])[0];

        expect($instance)->toBeAnInstanceOf(Variadic::class);
        // newInstanceArgs() must receive NO argument for the variadic: feeding
        // the guard's [] back in would pack $args as [[]] — one phantom element
        // a native `new Variadic()` never produces.
        expect($instance->run())->toBe([]);
    });

    it('should construct a typed variadic constructor natively through resolveInstance()', function () use ($container) {
        // The phantom [] is loud for typed variadics: as the single argument it
        // raises TypeError("... must be of type string, array given") — the
        // class constructs natively but not through us, even when the element
        // type is registered in the container.
        $instance = (new Resolver($container))->resolveCallable([TypedVariadic::class, 'parts'])[0];

        expect($instance->parts())->toBe([]);
    });

    it('should let a generic container failure propagate untouched', function () use ($brokenContainer, $exploding) {
        // Only NotFoundExceptionInterface — "entry absent" — is ours to handle:
        // it falls through the default chain. A generic ContainerExceptionInterface
        // (broken factory, circular reference) is the container orchestrator's
        // failure, not a resolution outcome, so it must escape as the very
        // instance the container threw — wrapping it in UnresolvableParameterException (as
        // this used to) made callers catch foreign plumbing in the library's
        // clothing.
        $resolver = new Resolver($brokenContainer());
        $param = (new ReflectionFunction(fn (stdClass $service) => $service))->getParameters()[0];

        expect(fn () => $resolver->resolveParameter($param))->toThrow($exploding);
    });

    it('should not let a default value mask a broken container entry', function () use ($brokenContainer, $exploding) {
        // A broken entry must fail loudly even when the parameter has a default.
        // NotFound falls through to the default (pinned by the ?Unregistered
        // $u = null spec above); a generic failure does not — the container's
        // own exception surfaces instead of the default quietly swallowing a
        // wiring bug (broken factory, circular reference) as null deep in the
        // caller's code.
        $resolver = new Resolver($brokenContainer());
        $param = (new ReflectionFunction(fn (?stdClass $service = null) => $service))->getParameters()[0];

        expect(fn () => $resolver->resolveParameter($param))->toThrow($exploding);
    });

    it('should throw UnresolvableParameterException when an untyped parameter is missing from the container', function () use ($container) {
        // Untyped parameters resolve by bare name; when the container has no such
        // entry and there is no default, the failure must surface as
        // UnresolvableParameterException naming the parameter — not as the container's own
        // NotFoundExceptionInterface escaping the interface's @throws contract.
        $param = (new ReflectionFunction(fn ($missing) => $missing))->getParameters()[0];

        expect(fn () => (new Resolver($container))->resolveParameter($param))
            ->toThrow(new UnresolvableParameterException($param));
    });

    it('should let a generic container failure propagate through the name lookup', function () use ($brokenContainer, $exploding) {
        // Same contract as the class-typed branch: the bare-name lookup handles
        // only NotFound (falling through to UnresolvableParameterException at the end) and
        // lets any other container failure escape untouched — a broken factory
        // must surface as the container's own exception, never be re-labelled
        // as a resolution failure by the library.
        $resolver = new Resolver($brokenContainer());
        $param = (new ReflectionFunction(fn ($service) => $service))->getParameters()[0];

        expect(fn () => $resolver->resolveParameter($param))->toThrow($exploding);
    });
});
