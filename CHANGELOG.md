# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

## [0.4.1](https://github.com/projek-xyz/callable/compare/v0.4.0...v0.4.1) (2026-10-09)

### Features

* expose `Handler::` as readonly property ([20dac3c](https://github.com/projek-xyz/callable/commit/20dac3c254043892ce957a04e717d57e0fa9ed5a))

## [0.4.0](https://github.com/projek-xyz/callable/compare/v0.3.1...v0.4.0) (2026-10-09)

### ⚠ BREAKING CHANGES

* Handler no longer accepts a PSR-11 ContainerInterface or
  looks the resolver up itself; callers pass a ResolverInterface directly.
* ResolverInterface implementations must add
  resolveArguments(array $parameters, array $provided): array.
* ResolverInterface::resolveInstance gains a required
  implementation parameter array $args = []; construction now runs through
  resolveArguments() and builds the class exactly once.
* the Projek\Callable\ParametersHelper trait no longer
  exists; Handler delegates to ResolverInterface::resolveArguments().

* drop ParametersHelper in favour of resolver-owned binding ([28d3fc2](https://github.com/projek-xyz/callable/commit/28d3fc21ab1c86e5857ae7f91ff17f3f1272766e))
* require ResolverInterface in Handler constructor ([6a91f2a](https://github.com/projek-xyz/callable/commit/6a91f2a3d8ce78fd1ca8032907ad3d0b80c510ad))

### Features

* bind constructor arguments in resolveInstance($entry, $args) ([624ca8b](https://github.com/projek-xyz/callable/commit/624ca8b36926b1d5f51369448abafa871916d8a7))
* expose resolveArguments() on ResolverInterface ([5bd2a13](https://github.com/projek-xyz/callable/commit/5bd2a13e972e5c1cdc4093b54a673515e3fdde8d))

## [0.3.1](https://github.com/projek-xyz/callable/compare/v0.3.0...v0.3.1) (2026-10-08)

## [0.3.0](https://github.com/projek-xyz/callable/compare/v0.2.0...v0.3.0) (2026-10-07)

### ⚠ BREAKING CHANGES

* callers catching `DependencyException` or
  `UnresolvableException` must update to the new class names.

* rename exception classes and add ResolverExceptionInterface ([bb6fc8c](https://github.com/projek-xyz/callable/commit/bb6fc8cd3c22c6506d1ede2c4e39571f0d8a53d3))

## [0.2.0](https://github.com/projek-xyz/callable/compare/v0.1.0...v0.2.0) (2026-10-07)

### ⚠ BREAKING CHANGES

* callers relying on wrapped container failures must
  catch `ContainerExceptionInterface` themselves; `DependencyException`
  now chains only the `NotFoundExceptionInterface` as previous.

* propagate generic container exceptions untouched ([#2](https://github.com/projek-xyz/callable/issues/2)) ([0a91fe1](https://github.com/projek-xyz/callable/commit/0a91fe13586e36a2b64b0189ec080b3f8867e6fe))

### Features

* add dev tool ([#3](https://github.com/projek-xyz/callable/issues/3)) ([3951828](https://github.com/projek-xyz/callable/commit/3951828b255f685372c5e109b48be0529a90ff1d))
* **dev:** add `pint.json` config with additional rules ([64f013a](https://github.com/projek-xyz/callable/commit/64f013a71ab83a03b4735687fd26f99c0ef1fd3f))

## 0.1.0 (2026-10-05)

### Features

* the essential ([#1](https://github.com/projek-xyz/callable/issues/1)) ([bbcb7f7](https://github.com/projek-xyz/callable/commit/bbcb7f783672d1726a533d0f51eb4392e5d87ce6))
