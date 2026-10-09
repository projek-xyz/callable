<?php

declare(strict_types=1);

use Projek\Callable\Handler;
use Projek\Callable\Resolver;
use Projek\Callable\ResolverInterface;
use Projek\Callable\UnresolvableCallableException;
use Projek\Callable\UnresolvableParameterException;
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

    // Optional $resolver lets a spec inject a custom ResolverInterface
    // ($handler($fixedResolver(...))); by default every spec runs against the
    // bundled Resolver wired to the shared FakeContainer.
    $handler = static fn (?ResolverInterface $resolver = null) => new Handler($resolver ?? new Resolver($container));

    // A custom ResolverInterface that returns a fixed value regardless of input:
    // lets specs drive createReflection()'s defensive guards, which the bundled
    // Resolver can never reach (it rejects shorthand strings and __call() pairs
    // inside resolveCallable() first).
    $fixedResolver = fn (mixed $resolved) => new class($resolved) implements ResolverInterface
    {
        public function __construct(private mixed $resolved)
        {
            // .
        }

        public function resolveCallable($callable): callable
        {
            return $this->resolved;
        }

        public function resolveArguments(array $parameters, array $provided): array
        {
            throw new LogicException('resolveArguments() must not run in this spec.');
        }

        public function resolveParameter(ReflectionParameter $param): mixed
        {
            throw new LogicException('resolveParameter() must not run in this spec.');
        }

        public function resolveInstance(string $entry, array $args = []): object
        {
            throw new LogicException('resolveParameter() must not run in this spec.');
        }
    };

    it('should accept a ResolverInterface instance', function () use ($handler) {
        expect($handler()->handle(fn () => 'ok'))->toBe('ok');
    });

    it('should reject a container passed in place of a resolver', function () {
        // The constructor no longer looks the resolver up in a PSR-11 container —
        // wiring is the caller's job now. The old constructor's argument (a bare
        // container) must fail loudly at construction instead of being silently
        // wrapped, so the breaking change surfaces at the wiring site.
        /** @disregard */
        expect(fn () => new Handler(new FakeContainer))->toThrow(new TypeError);
    });

    it('should invoke a closure with explicitly passed parameters', function () use ($handler) {
        // Baseline happy path: positional $params must line up with the callable's
        // parameters and the return value must pass through untouched.
        expect($handler()->handle(fn (int $a, int $b) => $a + $b, [2, 3]))->toBe(5);
    });

    it('should invoke a plain function string', function () use ($handler) {
        // Global functions are valid callables: they must bypass class resolution and
        // reflect normally, with their (built-in typed) parameters fed positionally.
        expect($handler()->handle('strtoupper', ['hello']))->toBe('HELLO');
    });

    it('should invoke a "Class::method" string', function () use ($handler) {
        // The string shorthand is the library's headline feature; end-to-end it must
        // resolve the class through the container and invoke the method — none of
        // this path was covered before (Handler had 0% coverage).
        expect($handler()->handle('Stubs\Registered::bar'))->toBeNull();
    });

    it('should invoke a [Class, method] pair and instantiate unregistered classes', function () use ($handler) {
        // Unregistered classes must still work through reflection-based construction
        // with their own constructor dependencies injected (Unregistered needs
        // Registered). Because the resolver replaces the class-string with an
        // instance before reflection, the pair arrives as [$object, method].
        expect($handler()->handle([Unregistered::class, 'bar']))->toBeNull();
    });

    it('should invoke an [$object, method] pair', function () use ($container, $handler) {
        // Instance callables bypass class resolution entirely — no class lookup
        // and no instantiation happen for an already-resolved object.
        $registered = $container->get(Registered::class);

        expect($handler()->handle([$registered, 'bar']))->toBeNull();
    });

    it('should invoke an object with __invoke()', function () use ($handler) {
        // Invokable objects are a ubiquitous callable shape; handle() must reflect
        // __invoke() — including defaulting its optional parameters.
        expect($handler()->handle(new Invokable))->toBe('invoked');
    });

    it('should inject type-hinted parameters from the container', function () use ($handler) {
        // The core value of the library: a callable declaring `Registered $r`
        // receives the container's instance without the caller passing anything.
        expect($handler()->handle(fn (Registered $r) => $r))->toBeAnInstanceOf(Registered::class);
    });

    it('should ignore parameters the callable does not declare', function () use ($handler) {
        // Extra arguments are dropped rather than raising ArgumentCountError —
        // mirrors PHP's own tolerance for extra arguments on userland functions.
        // Pinning it down so a future strict mode is a conscious decision.
        expect($handler()->handle(fn () => 'ok', ['unused', 'args']))->toBe('ok');
    });

    it('should honour named arguments passed to handle()', function () use ($handler) {
        // PHP 8 arrays with string keys are named arguments, and call_user_func_array
        // supports them natively. An earlier handle() only looked up
        // $params[$position], so named arguments fell through to container lookup by
        // parameter name and died with UnresolvableParameterException instead of binding by
        // name. Pin the native binding: anyone migrating a direct call to handle()
        // carries named arguments with them.
        expect($handler()->handle(fn (string $a, string $b) => $a.$b, ['a' => 'x', 'b' => 'y']))->toBe('xy');
    });

    it('should prefer an explicitly passed argument over the container', function () use ($handler) {
        // Binding caller-provided values is exclusively Handler's job (the spec
        // moved this precedence out of Resolver::resolveParameter()): a value at
        // the parameter's position must win over container auto-wiring.
        $explicit = new Registered;

        expect($handler()->handle(fn (Registered $r) => $r, [$explicit]))->toBe($explicit);
    });

    it('should bind an explicitly passed null instead of auto-wiring', function () use ($handler) {
        // array_key_exists(), not isset(): an explicit null means "provided" —
        // the container must not shadow it with its own instance, or callers
        // cannot reset an optional dependency.
        expect($handler()->handle(fn (?Registered $r = null) => $r, [null]))->toBeNull();
    });

    it('should prefer a named argument over the container for a class-typed parameter', function () use ($handler) {
        // The named-key path has the same ownership as the positional one — the
        // caller's instance must bind, not the container's.
        $explicit = new Registered;

        expect($handler()->handle(fn (Registered $r) => $r, ['r' => $explicit]))->toBe($explicit);
    });

    it('should propagate by-reference parameters', function () use ($handler) {
        // Callables that mutate their arguments (`function (Result &$out)`) are a
        // normal PHP pattern. The old value pipeline (array_map →
        // call_user_func_array) dropped the reference: the caller got a "must be
        // passed by reference" warning AND an unchanged variable, so output-style
        // callables silently produced nothing. Pin that the write now lands on the
        // caller's variable.
        $reference = 'original';

        $handler()->handle(function (&$arg) {
            $arg = 'changed';
        }, [&$reference]);

        expect($reference)->toBe('changed');
    });

    it('should reject callables that rely on __call()', function () use ($handler) {
        // Decision: __call()-based "methods" (proxies, magic services) satisfy
        // is_callable() but have no real method to reflect, so their parameter
        // type-hints could never be honoured — invoking them would bypass every
        // guarantee this library makes. Reject them with UnresolvableCallableException
        // instead: previously this crashed with an unrelated
        // `Error: Class "RdKafka\Exception" not found` because Handler imported
        // Exception from ext-rdkafka, which is not even a dependency.
        expect(fn () => $handler()->handle([new Dynamic, 'anyMethodHere']))
            ->toThrow(UnresolvableCallableException::methodNotFound(Dynamic::class, 'anyMethodHere'));
    });

    it('should reject a shorthand "Class::method" string returned by a custom resolver', function () use ($fixedResolver, $handler) {
        // ResolverInterface admits any is_callable() value — including static
        // shorthand strings, which function_exists() cannot reflect (it only
        // knows native functions). createReflection() must fail with a
        // diagnosis naming the offending callable instead of an internal
        // reflection error.
        expect(fn () => $handler($fixedResolver('Stubs\StaticOnly::make'))->handle('ignored'))
            ->toThrow(UnresolvableCallableException::invalidCallable('Stubs\StaticOnly::make'));
    });

    it('should reject an __call()-only pair returned by a custom resolver', function () use ($fixedResolver, $handler) {
        // The bundled Resolver rejects __call() pairs inside resolveCallable();
        // a custom resolver may not. createReflection() must still fail with a
        // message naming the missing method rather than reflecting nothing and
        // crashing later with an unrelated error.
        expect(fn () => $handler($fixedResolver([new Dynamic, 'anyMethodHere']))->handle('ignored'))
            ->toThrow(UnresolvableCallableException::methodNotFound(Dynamic::class, 'anyMethodHere'));
    });

    it('should collect all arguments for a variadic callable', function () use ($handler) {
        // handle() maps over REFLECTION parameters, where a variadic parameter is a
        // single entry — so an earlier implementation kept only $params[0] and
        // silently discarded arguments 2..n, handing variadic callables
        // (`fn (string $cmd, ...$args)`) a truncated list. Pin the full collection.
        expect($handler()->handle(fn (...$args) => count($args), [1, 2, 3]))->toBe(3);
    });

    it('should treat a variadic callable with no arguments as empty', function () use ($handler) {
        // Same variadic spot one level up: resolveParameter() sees isOptional() ===
        // true and would call getDefaultValue(), which raises ReflectionException
        // for variadics. A variadic call with zero arguments must simply produce
        // an empty argument list.
        expect($handler()->handle(fn (...$args) => count($args), []))->toBe(0);
    });

    it('should keep a built-in parameter default when the container uses its name', function () {
        // For built-in types the type-hint lookup degrades to the bare parameter name,
        // which used to let a container entry called 'count' silently replace the
        // callable's own default (a wrong-typed entry exploding with a TypeError).
        // The default written by the developer must not be shadowed by an unrelated
        // entry.
        $collision = new FakeContainer(['count' => fn () => 7]);

        expect((new Handler(new Resolver($collision)))->handle(fn (int $count = 3) => $count))->toBe(3);
    });

    it('should pack variadic arguments exactly like a native call', function () use ($handler) {
        // Native PHP packs extra positional arguments into the variadic with keys
        // renumbered from 0, then appends unmatched named arguments with their
        // string keys. handle() must produce the identical array so code invoked
        // through the library behaves the same as a direct call: the caller's
        // integer key 1 must NOT leak through as $args[1].
        expect($handler()->handle(
            fn (string $thing, ...$args) => $args,
            ['something', 'un-named arg', 'foo' => 'bar', 'bar' => 'baz']
        ))->toBe([
            0 => 'un-named arg',
            'foo' => 'bar',
            'bar' => 'baz',
        ]);
    });

    it('should keep a consumed named argument out of the variadic', function () use ($handler) {
        // The named key that bound a fixed parameter belongs to that parameter —
        // it must not leak into the variadic as a duplicate. Native
        // f(a: 'x', foo: 'y') gives $rest = ['foo' => 'y'], not both keys.
        expect($handler()->handle(
            fn ($a, ...$rest) => $rest,
            ['a' => 'x', 'foo' => 'y']
        ))->toBe(['foo' => 'y']);
    });

    it('should bind integer keys by order, not by key value', function () use ($handler) {
        // Native call_user_func_array() ignores the actual integer key values
        // ([5 => 'x'] feeds the first parameter); array_filter() — a very common
        // way to build $params — leaves sparse keys behind. Matching native means
        // renumbering positionally instead of keying by value, or every argument
        // after a filtered-out entry would land on the wrong parameter.
        expect($handler()->handle(fn (int $a, int $b) => [$a, $b], [5 => 10, 7 => 20]))->toBe([10, 20]);
    });

    it('should reject positional arguments that follow named ones', function () use ($handler) {
        // Native call_user_func_array() throws
        // Error('Cannot use positional argument after named argument') for this
        // key order. Mirroring it keeps handle() a drop-in for native invocation
        // instead of silently binding the arguments differently.
        expect(fn () => $handler()->handle(fn ($a, $b) => $a.$b, ['b' => 'y', 0 => 'x']))
            ->toThrow(new Error('Cannot use positional argument after named argument'));
    });

    it('should reject a named argument that overwrites a positional one', function () use ($handler) {
        // Native call_user_func_array() throws
        // Error('Named parameter $a overwrites previous argument') when a single
        // parameter is targeted twice; quietly preferring the positional value
        // would hide a genuine caller bug.
        expect(fn () => $handler()->handle(fn ($a) => $a, [0 => 'positional', 'a' => 'named']))
            ->toThrow(new Error('Named parameter $a overwrites previous argument'));
    });

    it('should reject a named argument that matches no parameter', function () use ($handler) {
        // Native call_user_func_array() throws
        // Error('Unknown named parameter $foo') — a string key matching nothing
        // is a caller mistake (e.g. a typo in the name). Silently dropping it, or
        // letting auto-wiring mask it with a UnresolvableParameterException about some other
        // parameter, would let the bug pass unnoticed.
        expect(fn () => $handler()->handle(fn ($a) => $a, ['foo' => 'x']))
            ->toThrow(new Error('Unknown named parameter $foo'));
    });

    it('should let the engine throw TypeError for a mismatched argument type', function () use ($handler) {
        // handle() must not re-type-check arguments itself — the engine owns
        // type checks at the invocation site, exactly like a direct strict call.
        // This pins the weak-mode bug fix: an unqualified call_user_func_array()
        // inside namespace Projek\Callable made PHP invoke in weak mode and
        // silently coerce '2' to 2; the fully qualified call restores the native
        // TypeError. Instance check only: the message embeds both the closure's
        // file:line and src/Handler.php's line number, so it shifts with edits.
        expect(fn () => $handler()->handle(fn (int $n) => $n, ['2']))
            ->toThrow(new TypeError);
    });

    it('should surface UnresolvableParameterException when a required parameter cannot be auto-wired', function () use ($handler) {
        // Too few arguments is where the library deliberately diverges from
        // native: auto-wiring REPLACES ArgumentCountError. When nothing can fill
        // the parameter (built-in int type, no default, no container entry),
        // the failure names the parameter and position instead of only saying
        // "Too few arguments" — richer diagnostics for the same situation.
        $callable = fn (int $one, int $two) => $one + $two;
        $param = (new ReflectionFunction($callable))->getParameters()[0];

        expect(fn () => $handler()->handle($callable))
            ->toThrow(new UnresolvableParameterException($param));
    });

    it('should surface the native-style argument label for a plain function', function () use ($handler) {
        // The message mirrors native TypeError phrasing — leading with the
        // declaring function's name and the 1-BASED argument position — so a
        // failing handle('myFunc') reads like the direct call it replaced:
        // str_repeat's first parameter is a built-in type with no default and
        // nothing to auto-wire.
        $param = (new ReflectionFunction('str_repeat'))->getParameters()[0];

        expect(fn () => $handler()->handle('str_repeat'))
            ->toThrow(new UnresolvableParameterException($param));
    });

    it('should refuse to auto-wire a by-reference parameter', function () use ($handler) {
        // A container entry is a temporary: PHP lets the call succeed, but the
        // write through the reference lands on a value discarded when handle()
        // returns — an output-style callable would silently produce nothing.
        // The caller must pass the variable itself, so resolution fails loudly.
        $callable = function (&$out) {
            $out = 'written';
        };
        $param = (new ReflectionFunction($callable))->getParameters()[0];

        expect(fn () => $handler()->handle($callable))
            ->toThrow(new UnresolvableParameterException($param, 'by-reference parameter must be provided explicitly'));
    });
});
