# AGENTS.md: projek-xyz/callable

## Quickstart Commands

| Action | Command |
|--------|---------|
| Run all checks (lint + test) | `composer test` |
| Format code (Pint, Laravel preset) | `composer format` |
| Lint code (Pint, Laravel preset) | `composer lint` |
| Run specs/Kahlan tests | `composer spec` |
| Install dependencies | `composer install` |

## Project Structure

```
src/              # Source code (PSR-4: Projek\Callable\):
  Handler.php     # Main entrypoint: resolves callables and invokes them
  Resolver.php    # Resolves callables from a PSR-11 container
  ResolverInterface.php  # Contract for resolveCallable()/resolveArguments()/resolveParameter()/resolveInstance()
  UnresolvableParameterException.php  # Thrown when a parameter is not resolvable
  UnresolvableCallableException.php  # Thrown when a callable cannot be resolved
  ResolverExceptionInterface.php  # Marker interface both exceptions implement
tests/
  spec/           # Kahlan specification tests
    Conventions.spec.php
    Handler.spec.php
    Resolver.spec.php
  stubs/          # Test stubs
    Registered.php      # Simple class with a bar() method
    Unregistered.php    # Class with a Registered dependency in constructor
    Dynamic.php         # Class with a magic __call() method
    Invokable.php       # Class with an __invoke() method
    StaticOnly.php      # Class with a static method and constructor dependencies
    Status.php          # Backed enum used for default-value scenarios
    Variadic.php        # Class with a variadic constructor and run() method
    TypedVariadic.php   # Class with a typed (string) variadic constructor and parts() method
    FakeContainer.php   # In-memory PSR-11 container double (memoizing, failure-injectable)
    NotFound.php        # PSR-11 NotFoundExceptionInterface thrown by FakeContainer
  config.php      # Kahlan config (coverage, stubs dir)
vendor/           # Composer dependencies
```

## Key Classes

### `Handler`
- **Entry point** for invoking callables with dependency injection
- Constructor accepts a `ResolverInterface` — no container wiring here; the container wires one for you (see Container Integration)
- `handle($callable, $params)` resolves the callable via `resolveCallable()`, builds the full argument list via `ResolverInterface::resolveArguments()` (position → named binding for what the caller provided, auto-wiring from the container for the rest), then invokes
- Argument binding mirrors native semantics (implemented in `Resolver::resolveArguments()`):
  - Integer keys are **positional by order** — the key values are ignored (`[5 => 'x']` feeds the first parameter; sparse keys from `array_filter()` still line up)
  - String keys are **named arguments** and bind by parameter name
  - A trailing variadic collects all remaining arguments: leftover positional keys are renumbered from `0`, unmatched named keys keep their string keys
  - References into `$params` are preserved so by-reference parameters keep mutating the caller's variables
- Native `Error`s are mirrored: `Cannot use positional argument after named argument`, `Named parameter $x overwrites previous argument`, `Unknown named parameter $x`
- Supports: strings, closures, `[Class, method]` arrays, `[$obj, 'method']` arrays, and `__invoke` objects

### `Resolver`
- Implements `ResolverInterface`; wraps a `Psr\Container\ContainerInterface`
- `resolveCallable($callable)` — resolves a callable string/array into a callable; container lookups handle only `NotFoundExceptionInterface` (a missing class entry falls back to reflection instantiation) — any other `ContainerExceptionInterface` (broken factory, circular reference) is **not caught — it propagates untouched**, same contract as `resolveParameter()`
  - Static `Class::method` (string or array form) → returned as `[Class::class, 'method']` without consulting the container or instantiating
  - Non-static `Class::method` → the class is resolved from the container (or instantiated if unregistered), yielding `[instance, 'method']`
  - Bare invokable class-string (`MyHandler::class` with `__invoke`) → resolved to its instance
  - `__call()`-only pairs → rejected with `UnresolvableCallableException` (no real method to reflect)
  - Closures/callables → returned as-is
  - Otherwise → throws `UnresolvableCallableException`
- `resolveArguments($parameters, $provided)` — builds the full argument list: native binding rules (integer keys are positional-by-order, string keys are named, leftover arguments spill into a trailing variadic, native `Error` ordering rules), `resolveParameter()` for every parameter the caller did not provide, and references into `$provided` preserved so by-reference parameters keep mutating the caller's variables
- `resolveParameter($param)` — resolves a single *missing* function/method parameter (pure auto-wiring — provided arguments are bound by `resolveArguments()` before this is called) with this precedence:
  1. Variadic parameters → `[]` (a guard: `resolveArguments()` splices variadic arguments itself; a direct call must not hand a spurious single value back)
  2. By-reference parameters → `UnresolvableParameterException` (a container/default value is a temporary; writes through the reference would be discarded)
  3. Container lookup by class type-hint (union/intersection types are **not** resolved — the declared type is only used in the error message). Only `NotFoundExceptionInterface` (entry absent) is handled — it falls through to steps 4–6; any other `ContainerExceptionInterface` (broken factory, circular reference) is **not caught and propagates untouched** — the container's orchestrator handles their own failures, and since the exception unwinds first, a generic failure never reaches the default; `UnresolvableParameterException` therefore chains only the `NotFoundExceptionInterface` as `previous`
  4. The parameter's default value
  5. Container lookup by bare parameter name — **only for untyped parameters**; built-in typed parameters keep their defaults instead of being shadowed by a name collision
  6. Otherwise throws `UnresolvableParameterException`
- `resolveInstance($entry, $args = [])` — binds `$args` against the constructor exactly like `handle()` binds `$params` (through `resolveArguments()`), then `ReflectionClass::newInstanceArgs()` — a single construction path that builds the class exactly once; non-instantiable entries (interfaces, enums, abstracts) throw `UnresolvableCallableException`

### `UnresolvableParameterException` / `UnresolvableCallableException`
- `UnresolvableParameterException` — thrown when a parameter cannot be resolved; constructed from the failing `ReflectionParameter` itself plus an optional `detail` appended to the message (used by the by-reference guard and for class/union type names) and the container's `NotFoundExceptionInterface` as `previous` (generic container failures are never wrapped — they propagate untouched). The message mirrors native `TypeError` phrasing: `{callable}(): Argument #{1-based position} ($name) is not resolvable[: detail]`, where `{callable}` is `Class::method` for methods, the plain function name for functions, or `{closure}` for anonymous functions (normalized — reflection can report a class-bound closure as `Scope::{closure:file:line}`). Matching static factories are parked/not requested yet.
- `UnresolvableCallableException` — thrown when a callable string/array cannot be resolved at all; **constructed only via named static factories** (the constructor is private, so every throw site must declare WHY):
  - `invalidCallable($callable, ?Throwable $previous = null)` — not (and cannot become) a callable: scalar, plain class-string, malformed array, private-method pair, non-`__invoke` object; message `<input> is not resolvable`
  - `notInstantiable(string $entry)` — class-like that can never be instantiated (enum, abstract); message `<entry> is not instantiable`
  - `methodNotFound($class, string $method)` — the pair's method does not exist, including `__call()`-only "methods"; message `Method <class>::<method>() does not exist`
- Every input (scalar, empty array, malformed pair) yields a non-empty message; the grammar is `... is not resolvable` (not `is not a resolvable`)
- `ResolverExceptionInterface` — marker interface (`extends Throwable`) implemented by both exceptions above; the single catch point for any resolution failure. It deliberately does **not** extend `Psr\Container\ContainerExceptionInterface` — container failures propagate untouched and must stay distinguishable from the library's own resolution failures
- `Handler::createReflection()` keeps two defensive guards for **custom** resolver output the bundled `Resolver` rejects earlier: shorthand `Class::method` strings → `invalidCallable()`, `__call()`-only pairs → `methodNotFound()`. The old "non-static method called statically" guard was removed as dead code: `ResolverInterface::resolveCallable(): callable` can never admit such a pair (`is_callable(['Cls', 'instanceMethod'])` is false → `TypeError` at return).

## How Resolution Works

1. **String callables** containing `::` are split into `[Class, method]`
2. **Static methods** short-circuit to `[Class::class, 'method']`; non-static pairs get their class resolved from the container (or instantiated if unregistered)
3. **Closures and callables** are returned unchanged; `__call()`-only pairs are rejected
4. **Unregistered class names** are instantiated via `ReflectionClass::newInstanceArgs()` — explicit constructor arguments are bound through `resolveArguments()`; missing constructor params are auto-wired from the container
5. **Parameter resolution** (for parameters the caller did not provide) follows the precedence listed under `resolveParameter` above (by-ref guard → class type-hint — NotFound falls through to the default, other container failures propagate untouched → default → untyped name lookup → `UnresolvableParameterException`)

## Coding Standards

- **Formatter**: `composer format` runs Pint with the Laravel preset
- **Linter**: `composer lint` runs Pint with the Laravel preset (`--test` mode fails on discrepancies)
- Both use the project's `composer.json` scripts
- PHP version requirement: `>=8.4` (defined in `composer.json`)

## Testing

- Test framework: **Kahlan** (v6.x)
- Spec files live in `tests/spec/**/*.spec.php` and use `describe`/`it` + `expect()` syntax
- Spec files should mirrors `src/` files structures: `src/Foo.php` -> `tests/spec/Foo.spec.php`; `src/Foo/Bar.php` -> `tests/spec/Foo/Bar.spec.php`
- Stub file live in `tests/stub/` with `Stubs\` PSR-4 prefix (autoload-dev)
- Run all tests with `composer spec`
- CI runs tests on PHP 8.4–8.5 matrix (`.github/workflows/tests.yml`), local dev pins PHP 8.4 via `.tool-versions` (asdf/mise)
- The suite currently reports 100% coverage (85/85 statements) — new `src/` code needs specs to keep it there (CI coverage driver: xdebug)

## Container Integration

The library is designed to work with any PSR-11 compatible container. The `Resolver` wraps a `Psr\Container\ContainerInterface`; `Handler` is constructed with a `ResolverInterface` — `projek-xyz/container` wires the pair for you. When a parameter or class name is not found in the container, the `Resolver` will attempt to instantiate the class via reflection (for unregistered entries) or throw an `UnresolvableParameterException`/`UnresolvableCallableException`. Container failures **other than** a missing entry (`ContainerExceptionInterface` for a broken factory, circular reference, …) are not caught — by `resolveParameter()`, `resolveArguments()` nor `resolveCallable()` — they propagate untouched to the orchestrator, who owns the container.

## Conventions to Note

- All source files use `declare(strict_types=1)`, **except** `*Exception.php` and `*Interface.php` (pattern-based exemption — they carry no calls whose coercion the flag could change; see `Conventions.spec.php`)
- Throw `UnresolvableCallableException` only through its named static factories (`invalidCallable()`, `notInstantiable()`, `methodNotFound()`) — never `new`, the constructor is private; `UnresolvableParameterException` keeps its public constructor taking the `ReflectionParameter`
- Both resolver exceptions implement `ResolverExceptionInterface` — any new failure thrown from the resolution paths in `Resolver`/`Handler` must implement it too
- Every class is declared `final` (source **and** test stubs; interfaces and enums excepted) — the library is not designed for inheritance
- Files are namespaced under `Projek\Callable` (or `Stubs` for test helpers)
- The `Resolver` is the central piece — keep its logic consistent: resolve → instantiate → resolve params
- When adding new callable types, modify `Resolver::resolveCallable()` and keep the method chain predictable
- `Handler::handle()` aims to behave like native `call_user_func_array()` **except** that missing parameters are auto-wired from the container; when mirroring native behaviour, verify the actual engine behaviour with a snippet before assuming
