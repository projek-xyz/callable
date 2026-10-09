[![Version](https://img.shields.io/packagist/v/projek-xyz/callable?style=flat-square)](https://packagist.org/packages/projek-xyz/callable)
[![License](https://img.shields.io/github/license/projek-xyz/callable?style=flat-square)](https://github.com/projek-xyz/callable/blob/main/LICENSE)
[![Actions Status](https://img.shields.io/github/actions/workflow/status/projek-xyz/callable/tests.yml?branch=main&style=flat-square)](https://github.com/projek-xyz/callable/actions)
[![Coverage Status](https://img.shields.io/coveralls/github/projek-xyz/callable/main?style=flat-square&logo=coveralls)](https://coveralls.io/github/projek-xyz/callable)

# Auto-wire and invoke any callable through PSR-11

Resolve any callable through a PSR-11 container and invoke it with dependency injection — same semantics as native `call_user_func_array()`, except missing parameters auto-wire from the container instead of raising `ArgumentCountError`.

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
use Projek\Callable\Resolver;
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
// Handler takes a resolver — projek-xyz/container wires this pair for you,
// so a bare Handler only needs manual wiring like here.
$handler = new Handler(new Resolver($container));

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

- **`UnresolvableParameterException`** — a parameter could not be provided or auto-wired. The message mirrors native `TypeError` phrasing with the 1-based argument position:

  ```php
  $handler->handle(fn (string $dsn) => $dsn);
  // Projek\Callable\UnresolvableParameterException:
  // {closure}(): Argument #1 ($dsn) is not resolvable
  ```

- **`UnresolvableCallableException`** — the callable itself cannot be resolved:

  ```text
  Instance of missing_function is not resolvable
  Method Greeter::nope() does not exist
  ```

Both implement **`ResolverExceptionInterface`**, a single catch point for any resolution failure:

```php
use Projek\Callable\ResolverExceptionInterface;

try {
    $handler->handle($callable);
} catch (ResolverExceptionInterface $e) {
    // any resolution failure
}
```

Container failures beyond a missing entry (a broken factory, a circular reference — PSR-11's generic `ContainerExceptionInterface`) are not translated: they propagate to you exactly as your container threw them, since only `NotFoundExceptionInterface` is the library's to handle.

- **`TypeError`** — argument type mismatches surface PHP's own `TypeError` untouched: invocation runs natively under `strict_types`.

## License

This library is open-sourced software licensed under [MIT license](LICENSE).
