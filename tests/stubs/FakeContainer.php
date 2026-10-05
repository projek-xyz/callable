<?php

namespace Stubs;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * Minimal PSR-11 container double — the specs depend on the PSR-11 contract
 * only, never on a concrete package. Two behaviours are load-bearing:
 *
 * - Factories are memoized (shared-instance semantics): specs assert identity
 *   via `->toBe($container->get(...))`, which a fresh-instance-per-get fake
 *   would break — and with it the explicit-vs-container precedence tests.
 * - `failWith` rethrows a generic ContainerExceptionInterface on every get(),
 *   modelling PSR-11's broken-factory/circular-reference case as distinct
 *   from a missing entry (which throws NotFound instead).
 */
class FakeContainer implements ContainerInterface
{
    /** @var array<string, callable|mixed> */
    private array $entries;

    /** @var array<string, mixed> Memoized results — get() must share instances. */
    private array $resolved = [];

    /**
     * @param  array<string, callable|mixed>  $entries  Callables are treated as factories (invoked once), anything else is a value
     * @param  ContainerExceptionInterface|null  $failWith  When set, every get() rethrows it — broken-container scenarios
     */
    public function __construct(
        array $entries = [],
        private readonly ?ContainerExceptionInterface $failWith = null,
    ) {
        $this->entries = $entries;
    }

    /**
     * Late registration, for factories that need the container itself
     * (e.g. `fn () => new Resolver($bound)` in Handler.spec) — re-registering
     * also drops any memoized result so the new factory wins.
     */
    public function set(string $id, callable $factory): void
    {
        $this->entries[$id] = $factory;
        unset($this->resolved[$id]);
    }

    public function get(string $id): mixed
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if (\array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (! \array_key_exists($id, $this->entries)) {
            throw new NotFound($id);
        }

        $entry = $this->entries[$id];

        return $this->resolved[$id] = \is_callable($entry) ? $entry() : $entry;
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->entries);
    }
}
