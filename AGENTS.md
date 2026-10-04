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
  ResolverInterface.php  # Contract for resolveCallable()/resolveParameter()
  DependencyException.php  # Thrown when a dependency is not resolvable
  UnresolvableException.php  # Thrown when a callable cannot be resolved
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
  config.php      # Kahlan config (coverage, stubs dir)
vendor/           # Composer dependencies
```

## Key Classes

### `Handler`
- **Entry point** for invoking callables with dependency injection
- Constructor accepts `Psr\Container\ContainerInterface`
- `handle($callable, $params)` resolves the callable via `Resolver`, normalizes `$params` via `normalizeArguments()` (see below), binds provided arguments exclusively in `Handler` (position → named), auto-wires anything the caller did not provide, then invokes
- Argument binding mirrors native semantics:
  - Integer keys are **positional by order** — the key values are ignored (`[5 => 'x']` feeds the first parameter; sparse keys from `array_filter()` still line up)
  - String keys are **named arguments** and bind by parameter name
  - A trailing variadic collects all remaining arguments: leftover positional keys are renumbered from `0`, unmatched named keys keep their string keys
  - References into `$params` are preserved so by-reference parameters keep mutating the caller's variables
- Native `Error`s are mirrored: `Cannot use positional argument after named argument`, `Named parameter $x overwrites previous argument`, `Unknown named parameter $x`
- Supports: strings, closures, `[Class, method]` arrays, `[$obj, 'method']` arrays, and `__invoke` objects

### `Resolver`
- Implements `ResolverInterface`; wraps a `Psr\Container\ContainerInterface`
- `resolveCallable($callable)` — resolves a callable string/array into a callable
  - Static `Class::method` (string or array form) → returned as `[Class::class, 'method']` without consulting the container or instantiating
  - Non-static `Class::method` → the class is resolved from the container (or instantiated if unregistered), yielding `[instance, 'method']`
  - Bare invokable class-string (`MyHandler::class` with `__invoke`) → resolved to its instance
  - `__call()`-only pairs → rejected with `UnresolvableException` (no real method to reflect)
  - Closures/callables → returned as-is
  - Otherwise → throws `UnresolvableException`
- `resolveFromContainer($entry)` — gets from container; if not found, instantiates the class via reflection (injecting constructor params from container); generic `ContainerExceptionInterface` failures are wrapped in `UnresolvableException`
- `resolveParameter($param)` — resolves a single *missing* function/method parameter (pure auto-wiring — provided arguments are bound by `Handler` before this is called) with this precedence:
  1. Variadic parameters → `[]` (a guard: `Handler` splices variadic arguments itself, and `createInstance()` must not call `getDefaultValue()` on a variadic constructor)
  2. By-reference parameters → `DependencyException` (a container/default value is a temporary; writes through the reference would be discarded)
  3. Container lookup by class type-hint (union/intersection types are **not** resolved — the declared type is only used in the error message)
  4. The parameter's default value
  5. Container lookup by bare parameter name — **only for untyped parameters**; built-in typed parameters keep their defaults instead of being shadowed by a name collision
  6. Otherwise throws `DependencyException`
- `createInstance($entry)` — `ReflectionClass::newInstanceArgs()` for unregistered classes; non-instantiable entries (interfaces, enums, abstracts) throw `UnresolvableException`

### `DependencyException` / `UnresolvableException`
- `DependencyException` — thrown when a parameter cannot be resolved; structured public constructor (`name`, `position`, the container exception as `previous`, and an optional `detail` appended to the message, used by the by-reference guard). Matching static factories are parked/not requested yet.
- `UnresolvableException` — thrown when a callable string/array cannot be resolved at all; **constructed only via named static factories** (the constructor is private, so every throw site must declare WHY):
  - `invalidCallable($callable, ?Throwable $previous = null)` — not (and cannot become) a callable: scalar, plain class-string, malformed array, private-method pair, non-`__invoke` object; message `<input> is not resolvable`
  - `invalidContainerEntry(string $entry, Throwable $previous)` — `get()` failed with a non-NotFound `ContainerExceptionInterface` (broken factory, circular reference); message `Failed to resolve <entry>: <cause>`
  - `notInstantiable(string $entry)` — class-like that can never be instantiated (enum, abstract); message `<entry> is not instantiable`
  - `methodNotFound($class, string $method)` — the pair's method does not exist, including `__call()`-only "methods"; message `Method <class>::<method>() does not exist`
- Every input (scalar, empty array, malformed pair, broken container entry) yields a non-empty message; the grammar is `... is not resolvable` (not `is not a resolvable`)
- `Handler::createReflection()` keeps two defensive guards for **custom** resolver output the bundled `Resolver` rejects earlier: shorthand `Class::method` strings → `invalidCallable()`, `__call()`-only pairs → `methodNotFound()`. The old "non-static method called statically" guard was removed as dead code: `ResolverInterface::resolveCallable(): callable` can never admit such a pair (`is_callable(['Cls', 'instanceMethod'])` is false → `TypeError` at return).

## How Resolution Works

1. **String callables** containing `::` are split into `[Class, method]`
2. **Static methods** short-circuit to `[Class::class, 'method']`; non-static pairs get their class resolved from the container (or instantiated if unregistered)
3. **Closures and callables** are returned unchanged; `__call()`-only pairs are rejected
4. **Unregistered class names** are instantiated via `ReflectionClass::newInstanceArgs()`, with constructor params resolved from the container
5. **Parameter resolution** follows the precedence listed under `resolveParameter` above (by-ref guard → class type-hint → default → untyped name lookup → `DependencyException`)

## Coding Standards

- **Formatter**: `composer format` runs Pint with the Laravel preset
- **Linter**: `composer lint` runs Pint with the Laravel preset (`--test` mode fails on discrepancies)
- Both use the project's `composer.json` scripts
- PHP version requirement: `>=8.4` (defined in `composer.json`)

## Testing

- Test framework: **Kahlan** (v6.x)
- Spec files live in `tests/spec/` and use `describe/it/expect` syntax
- Stub classes for container lookups live in `tests/stubs/`
- Run all tests with `composer spec`
- CI runs tests on PHP 8.4–8.5 matrix (`.github/workflows/tests.yml`)

## Container Integration

The library is designed to work with any PSR-11 compatible container. The `Handler` and `Resolver` both accept a `Psr\Container\ContainerInterface`. When a parameter or class name is not found in the container, the `Resolver` will attempt to instantiate the class via reflection (for unregistered entries) or throw a `DependencyException`/`UnresolvableException`.

## Conventions to Note

- All source files use `declare(strict_types=1)`, **except** `*Exception.php` and `*Interface.php` (pattern-based exemption — they carry no calls whose coercion the flag could change; see `Conventions.spec.php`)
- Throw `UnresolvableException` only through its named static factories (`invalidCallable()`, `invalidContainerEntry()`, `notInstantiable()`, `methodNotFound()`) — never `new`, the constructor is private; `DependencyException` keeps its structured public constructor
- Files are namespaced under `Projek\Callable` (or `Stubs` for test helpers)
- The `Resolver` is the central piece — keep its logic consistent: resolve → instantiate → resolve params
- When adding new callable types, modify `Resolver::resolveCallable()` and keep the method chain predictable
- `Handler::handle()` aims to behave like native `call_user_func_array()` **except** that missing parameters are auto-wired from the container; when mirroring native behaviour, verify the actual engine behaviour with a snippet before assuming