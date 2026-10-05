[![Version](https://img.shields.io/packagist/v/projek-xyz/callable?style=flat-square)](https://packagist.org/packages/projek-xyz/callable)
[![Lisence](https://img.shields.io/github/license/projek-xyz/callable?style=flat-square)](https://github.com/projek-xyz/callable/blob/main/LICENSE)
[![Actions Status](https://img.shields.io/github/actions/workflow/status/projek-xyz/callable/tests.yml?branch=main&style=flat-square)](https://github.com/projek-xyz/callable/actions)

# Project Callable

Generic callable resolver and handler library

## Installation

```sh
composer require projek-xyz/callable
```

## Resolving and invoking callables

Works with any [PSR-11](https://www.php-fig.org/psr/psr-11/) container; the examples below use [projek-xyz/container](https://github.com/projek-xyz/container).

### Invoking with Handler

`Handler::handle()` accepts any callable shape — a function name, `Class::method` string, `[Class::class, 'method']`, `[$object, 'method']`, a closure, or an `__invoke()` object — and binds arguments with native `call_user_func_array()` semantics: integer keys are positional (in order), string keys are named, and a trailing variadic absorbs the rest. Whatever you do not pass is auto-wired from the container (then its default value) instead of raising `ArgumentCountError`:

```php
use Projek\Callable\Handler;
use Projek\Container;

class Clock
{
    public function now(): int
    {
        return time();
    }
}

class Greeter
{
    public function __construct(private Clock $clock) {}

    public function greet(string $who): string
    {
        return sprintf('Hello %s (at %d)', $who, $this->clock->now());
    }
}

$container = new Container([Clock::class => Clock::class]);
$handler = new Handler($container);

// $who is bound by name; the Greeter receiver is built automatically and
// its Clock dependency comes from the container.
echo $handler->handle([Greeter::class, 'greet'], ['who' => 'world']);
// e.g. "Hello world (at 1791177991)"

// Positional arguments behave like any native call...
$handler->handle('strtoupper', ['hello']); // "HELLO"

// ...and so do Class::method strings, closures, and __invoke() objects.
$handler->handle(Greeter::class.'::greet', ['who' => 'PHP']);
```

### Resolving without invoking

`Resolver` is the layer `Handler` is built on; reach for it directly when you only need the callable:

```php
use Projek\Callable\Resolver;

$callable = (new Resolver($container))->resolveCallable(Greeter::class.'::greet');
// [new Greeter(new Clock), 'greet']
```

### Error handling

Failures are named the way PHP itself would name them:

- **`DependencyException`** — a parameter could not be provided or auto-wired. The message mirrors native `TypeError` phrasing with the 1-based argument position:

  ```php
  $handler->handle(fn (string $dsn) => $dsn);
  // Projek\Callable\DependencyException:
  // {closure}(): Argument #1 ($dsn) is not resolvable
  ```

- **`UnresolvableException`** — the callable itself cannot be resolved:

  ```text
  Instance of missing_function is not resolvable
  Method Greeter::nope() does not exist
  ```

- **`TypeError`** — argument type mismatches surface PHP's own `TypeError` untouched: invocation runs natively under `strict_types`.

## License

This library is open-sourced software licensed under [MIT license](LICENSE).
