# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

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
