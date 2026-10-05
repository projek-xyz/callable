<?php

declare(strict_types=1);

use Projek\Callable\DependencyException;
use Projek\Callable\Handler;
use Projek\Callable\Resolver;
use Projek\Callable\ResolverInterface;
use Projek\Callable\UnresolvableException;
use Psr\Container\ContainerExceptionInterface;
use Stubs\Dynamic;
use Stubs\FakeContainer;
use Stubs\Invokable;
use Stubs\Registered;
use Stubs\Unregistered;

use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\it;

describe(Handler::class, function () {
    $container = new FakeContainer([
        Registered::class => fn () => new Registered,
    ]);

    // A custom ResolverInterface that returns a fixed value regardless of input:
    // lets specs drive createReflection()'s defensive guards, which the bundled
    // Resolver can never reach (it rejects shorthand strings and __call() pairs
    // inside resolveCallable() first).
    $fixedResolver = fn (mixed $resolved) => new FakeContainer([
        ResolverInterface::class => fn () => new class($resolved) implements ResolverInterface
        {
            public function __construct(private mixed $resolved)
            {
                // .
            }

            public function resolveCallable($callable): callable
            {
                return $this->resolved;
            }

            public function resolveParameter(ReflectionParameter $param): mixed
            {
                throw new LogicException('resolveParameter() must not run in this spec.');
            }
        },
    ]);

    it('should fall back to a built-in resolver when ResolverInterface is not bound', function () {
        // The common out-of-the-box situation: the application's PSR-11 container
        // knows nothing about this library's ResolverInterface, so the constructor
        // must silently substitute the bundled Resolver — otherwise Handler is
        // unusable without extra wiring.
        $handler = new Handler(new FakeContainer);

        expect($handler->handle(fn () => 'fallback-ok'))->toBe('fallback-ok');
    });

    it('should prefer a container-provided resolver', function () {
        // When an application does bind ResolverInterface (e.g. a decorated or
        // extended resolver), the constructor's happy path must use that instance
        // instead of the fallback — this is the try-block branch of __construct().
        $bound = new FakeContainer;
        $bound->set(ResolverInterface::class, fn () => new Resolver($bound));

        $handler = new Handler($bound);

        expect($handler->handle(fn () => 'bound-resolver'))->toBe('bound-resolver');
    });

    it('should surface non-NotFound container errors while resolving its resolver', function () {
        // Only NotFoundExceptionInterface may trigger the fallback: a generic
        // ContainerExceptionInterface signals a real failure (broken factory, circular
        // reference) that must not be swallowed by quietly substituting another
        // resolver, or the misconfiguration would be discovered much later.
        $broken = new FakeContainer(
            failWith: new class('resolver factory exploded') extends RuntimeException implements ContainerExceptionInterface {},
        );

        $error = null;
        try {
            new Handler($broken);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(ContainerExceptionInterface::class);
    });

    it('should fail fast when the container returns an invalid resolver', function () {
        // A ResolverInterface entry resolving to something else is a wiring mistake.
        // The typed $resolver property turns it into a TypeError during construction —
        // loud and early beats half-working invocation later. (If a friendlier
        // exception is preferred, this documents the current fail-fast contract.)
        $wrong = new FakeContainer([
            ResolverInterface::class => fn () => new stdClass,
        ]);

        $error = null;
        try {
            new Handler($wrong);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(TypeError::class);
    });

    it('should invoke a closure with explicitly passed parameters', function () use ($container) {
        // Baseline happy path: positional $params must line up with the callable's
        // parameters and the return value must pass through untouched.
        $handler = new Handler($container);

        expect($handler->handle(fn (int $a, int $b) => $a + $b, [2, 3]))->toBe(5);
    });

    it('should invoke a plain function string', function () use ($container) {
        // Global functions are valid callables: they must bypass class resolution and
        // reflect normally, with their (built-in typed) parameters fed positionally.
        $handler = new Handler($container);

        expect($handler->handle('strtoupper', ['hello']))->toBe('HELLO');
    });

    it('should invoke a "Class::method" string', function () use ($container) {
        // The string shorthand is the library's headline feature; end-to-end it must
        // resolve the class through the container and invoke the method — none of
        // this path was covered before (Handler had 0% coverage).
        $handler = new Handler($container);

        expect($handler->handle('Stubs\Registered::bar'))->toBeNull();
    });

    it('should invoke a [Class, method] pair and instantiate unregistered classes', function () use ($container) {
        // Unregistered classes must still work through reflection-based construction
        // with their own constructor dependencies injected (Unregistered needs
        // Registered). Because the resolver replaces the class-string with an
        // instance before reflection, the pair arrives as [$object, method].
        $handler = new Handler($container);

        expect($handler->handle([Unregistered::class, 'bar']))->toBeNull();
    });

    it('should invoke an [$object, method] pair', function () use ($container) {
        // Instance callables bypass class resolution entirely — no class lookup
        // and no instantiation happen for an already-resolved object.
        $handler = new Handler($container);
        $registered = $container->get(Registered::class);

        expect($handler->handle([$registered, 'bar']))->toBeNull();
    });

    it('should invoke an object with __invoke()', function () use ($container) {
        // Invokable objects are a ubiquitous callable shape; handle() must reflect
        // __invoke() — including defaulting its optional parameters.
        $handler = new Handler($container);

        expect($handler->handle(new Invokable))->toBe('invoked');
    });

    it('should inject type-hinted parameters from the container', function () use ($container) {
        // The core value of the library: a callable declaring `Registered $r`
        // receives the container's instance without the caller passing anything.
        $handler = new Handler($container);

        expect($handler->handle(fn (Registered $r) => $r))->toBeAnInstanceOf(Registered::class);
    });

    it('should ignore parameters the callable does not declare', function () use ($container) {
        // Extra arguments are dropped rather than raising ArgumentCountError —
        // mirrors PHP's own tolerance for extra arguments on userland functions.
        // Pinning it down so a future strict mode is a conscious decision.
        $handler = new Handler($container);

        expect($handler->handle(fn () => 'ok', ['unused', 'args']))->toBe('ok');
    });

    it('should honour named arguments passed to handle()', function () use ($container) {
        // PHP 8 arrays with string keys are named arguments, and call_user_func_array
        // supports them natively — but handle() only looks up $params[$position], so
        // named arguments fall through to container lookup by parameter name and die
        // with DependencyException instead of binding by name. Anyone migrating a
        // direct call to handle() can hit this.
        $handler = new Handler($container);

        $error = null;
        $result = null;
        try {
            $result = $handler->handle(fn (string $a, string $b) => $a.$b, ['a' => 'x', 'b' => 'y']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeNull();
        expect($result)->toBe('xy');
    });

    it('should prefer an explicitly passed argument over the container', function () use ($container) {
        // Binding caller-provided values is exclusively Handler's job (the spec
        // moved this precedence out of Resolver::resolveParameter()): a value at
        // the parameter's position must win over container auto-wiring.
        $handler = new Handler($container);
        $explicit = new Registered;

        expect($handler->handle(fn (Registered $r) => $r, [$explicit]))->toBe($explicit);
    });

    it('should bind an explicitly passed null instead of auto-wiring', function () use ($container) {
        // array_key_exists(), not isset(): an explicit null means "provided" —
        // the container must not shadow it with its own instance, or callers
        // cannot reset an optional dependency.
        $handler = new Handler($container);

        expect($handler->handle(fn (?Registered $r = null) => $r, [null]))->toBeNull();
    });

    it('should prefer a named argument over the container for a class-typed parameter', function () use ($container) {
        // The named-key path has the same ownership as the positional one — the
        // caller's instance must bind, not the container's.
        $handler = new Handler($container);
        $explicit = new Registered;

        expect($handler->handle(fn (Registered $r) => $r, ['r' => $explicit]))->toBe($explicit);
    });

    it('should propagate by-reference parameters', function () use ($container) {
        // Callables that mutate their arguments (`function (Result &$out)`) are a
        // normal PHP pattern. The value pipeline (array_map → call_user_func_array)
        // drops the reference: today the caller gets a "must be passed by reference"
        // warning AND an unchanged variable, so output-style callables silently
        // produce nothing.
        $handler = new Handler($container);
        $reference = 'original';

        $error = null;
        try {
            $handler->handle(function (&$arg) {
                $arg = 'changed';
            }, [&$reference]);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeNull();
        expect($reference)->toBe('changed');
    });

    it('should reject callables that rely on __call()', function () use ($container) {
        // Decision: __call()-based "methods" (proxies, magic services) satisfy
        // is_callable() but have no real method to reflect, so their parameter
        // type-hints could never be honoured — invoking them would bypass every
        // guarantee this library makes. Reject them with UnresolvableException
        // instead: previously this crashed with an unrelated
        // `Error: Class "RdKafka\Exception" not found` because Handler imported
        // Exception from ext-rdkafka, which is not even a dependency.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle([new Dynamic, 'anyMethodHere']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(UnresolvableException::class);
        expect($error->getMessage())->not->toBe('');
    });

    it('should reject a shorthand "Class::method" string returned by a custom resolver', function () use ($fixedResolver) {
        // ResolverInterface admits any is_callable() value — including static
        // shorthand strings, which function_exists() cannot reflect (it only
        // knows native functions). createReflection() must fail with a
        // diagnosis naming the offending callable instead of an internal
        // reflection error.
        $handler = new Handler($fixedResolver('Stubs\StaticOnly::make'));

        expect(fn () => $handler->handle('ignored'))
            ->toThrow(UnresolvableException::invalidCallable('Stubs\StaticOnly::make'));
    });

    it('should reject an __call()-only pair returned by a custom resolver', function () use ($fixedResolver) {
        // The bundled Resolver rejects __call() pairs inside resolveCallable();
        // a custom resolver may not. createReflection() must still fail with a
        // message naming the missing method rather than reflecting nothing and
        // crashing later with an unrelated error.
        $handler = new Handler($fixedResolver([new Dynamic, 'anyMethodHere']));

        expect(fn () => $handler->handle('ignored'))
            ->toThrow(UnresolvableException::methodNotFound(Dynamic::class, 'anyMethodHere'));
    });

    it('should collect all arguments for a variadic callable', function () use ($container) {
        // handle() maps over REFLECTION parameters — a variadic parameter is a single
        // entry — so only $params[0] survives and arguments 2..n are silently
        // discarded. Variadic callables (`fn (string $cmd, ...$args)`) receive a
        // truncated argument list today.
        $handler = new Handler($container);

        expect($handler->handle(fn (...$args) => count($args), [1, 2, 3]))->toBe(3);
    });

    it('should treat a variadic callable with no arguments as empty', function () use ($container) {
        // Same variadic spot one level up: resolveParameter() sees isOptional() ===
        // true and calls getDefaultValue(), which raises ReflectionException for
        // variadics. A variadic call with zero arguments must simply produce an
        // empty argument list.
        $handler = new Handler($container);

        $error = null;
        $result = null;
        try {
            $result = $handler->handle(fn (...$args) => count($args), []);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeNull();
        expect($result)->toBe(0);
    });

    it('should keep a built-in parameter default when the container uses its name', function () {
        // For built-in types the type-hint lookup degrades to the bare parameter name,
        // so a container entry called 'count' silently replaces the callable's own
        // default (and a wrong-typed entry explodes with a TypeError). The default
        // written by the developer must not be shadowed by an unrelated entry.
        $collision = new FakeContainer([
            'count' => fn () => 7,
        ]);
        $handler = new Handler($collision);

        expect($handler->handle(fn (int $count = 3) => $count))->toBe(3);
    });

    it('should pack variadic arguments exactly like a native call', function () use ($container) {
        // Native PHP packs extra positional arguments into the variadic with keys
        // renumbered from 0, then appends unmatched named arguments with their
        // string keys. handle() must produce the identical array so code invoked
        // through the library behaves the same as a direct call: the caller's
        // integer key 1 must NOT leak through as $args[1].
        $handler = new Handler($container);

        expect($handler->handle(
            fn (string $thing, ...$args) => $args,
            ['something', 'un-named arg', 'foo' => 'bar', 'bar' => 'baz']
        ))->toBe([
            0 => 'un-named arg',
            'foo' => 'bar',
            'bar' => 'baz',
        ]);
    });

    it('should keep a consumed named argument out of the variadic', function () use ($container) {
        // The named key that bound a fixed parameter belongs to that parameter —
        // it must not leak into the variadic as a duplicate. Native
        // f(a: 'x', foo: 'y') gives $rest = ['foo' => 'y'], not both keys.
        $handler = new Handler($container);

        expect($handler->handle(
            fn ($a, ...$rest) => $rest,
            ['a' => 'x', 'foo' => 'y']
        ))->toBe(['foo' => 'y']);
    });

    it('should bind integer keys by order, not by key value', function () use ($container) {
        // Native call_user_func_array() ignores the actual integer key values
        // ([5 => 'x'] feeds the first parameter); array_filter() — a very common
        // way to build $params — leaves sparse keys behind. Matching native means
        // renumbering positionally instead of keying by value, or every argument
        // after a filtered-out entry would land on the wrong parameter.
        $handler = new Handler($container);

        expect($handler->handle(fn (int $a, int $b) => [$a, $b], [5 => 10, 7 => 20]))->toBe([10, 20]);
    });

    it('should reject positional arguments that follow named ones', function () use ($container) {
        // Native call_user_func_array() throws
        // Error('Cannot use positional argument after named argument') for this
        // key order. Mirroring it keeps handle() a drop-in for native invocation
        // instead of silently binding the arguments differently.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle(fn ($a, $b) => $a.$b, ['b' => 'y', 0 => 'x']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(Error::class);
        expect($error->getMessage())->toBe('Cannot use positional argument after named argument');
    });

    it('should reject a named argument that overwrites a positional one', function () use ($container) {
        // Native call_user_func_array() throws
        // Error('Named parameter $a overwrites previous argument') when a single
        // parameter is targeted twice; quietly preferring the positional value
        // would hide a genuine caller bug.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle(fn ($a) => $a, [0 => 'positional', 'a' => 'named']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(Error::class);
        expect($error->getMessage())->toBe('Named parameter $a overwrites previous argument');
    });

    it('should reject a named argument that matches no parameter', function () use ($container) {
        // Native call_user_func_array() throws
        // Error('Unknown named parameter $foo') — a string key matching nothing
        // is a caller mistake (e.g. a typo in the name). Silently dropping it, or
        // letting auto-wiring mask it with a DependencyException about some other
        // parameter, would let the bug pass unnoticed.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle(fn ($a) => $a, ['foo' => 'x']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(Error::class);
        expect($error->getMessage())->toBe('Unknown named parameter $foo');
    });

    it('should let the engine throw TypeError for a mismatched argument type', function () use ($container) {
        // handle() must not re-type-check arguments itself — the engine owns
        // type checks at the invocation site, exactly like a direct strict call.
        // This pins the weak-mode bug fix: an unqualified call_user_func_array()
        // inside namespace Projek\Callable made PHP invoke in weak mode and
        // silently coerce '2' to 2; the fully qualified call restores the native
        // TypeError. Instance check only: the message embeds both the closure's
        // file:line and src/Handler.php's line number, so it shifts with edits.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle(fn (int $n) => $n, ['2']);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(TypeError::class);
    });

    it('should surface DependencyException when a required parameter cannot be auto-wired', function () use ($container) {
        // Too few arguments is where the library deliberately diverges from
        // native: auto-wiring REPLACES ArgumentCountError. When nothing can fill
        // the parameter (built-in int type, no default, no container entry),
        // the failure names the parameter and position instead of only saying
        // "Too few arguments" — richer diagnostics for the same situation.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle(fn (int $one, int $two) => $one + $two);
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(DependencyException::class);
        expect($error->getMessage())->toBe('{closure}(): Argument #1 ($one) is not resolvable');
    });

    it('should surface the native-style argument label for a plain function', function () use ($container) {
        // The message mirrors native TypeError phrasing — leading with the
        // declaring function's name and the 1-BASED argument position — so a
        // failing handle('myFunc') reads like the direct call it replaced:
        // str_repeat's first parameter is a built-in type with no default and
        // nothing to auto-wire, the same shape as the test.php scenario.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle('str_repeat');
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(DependencyException::class);
        expect($error->getMessage())->toBe('str_repeat(): Argument #1 ($string) is not resolvable');
    });

    it('should refuse to auto-wire a by-reference parameter', function () use ($container) {
        // A container entry is a temporary: PHP lets the call succeed, but the
        // write through the reference lands on a value discarded when handle()
        // returns — an output-style callable would silently produce nothing.
        // The caller must pass the variable itself, so resolution fails loudly.
        $handler = new Handler($container);

        $error = null;
        try {
            $handler->handle(function (&$out) {
                $out = 'written';
            });
        } catch (Throwable $err) {
            $error = $err;
        }

        expect($error)->toBeAnInstanceOf(DependencyException::class);
        expect($error->getMessage())
            ->toBe('{closure}(): Argument #1 ($out) is not resolvable: by-reference parameter must be provided explicitly');
    });
});
