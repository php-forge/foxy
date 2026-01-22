# Testing

This package provides a consistent set of Composer scripts for local validation.

Tool references:

- [Composer Require Checker](https://github.com/maglnet/ComposerRequireChecker) for dependency definition checks.
- [Easy Coding Standard (ECS)](https://github.com/easy-coding-standard/easy-coding-standard) for coding standards.
- [Psalm](https://psalm.dev/) for static analysis.
- [PHPUnit](https://phpunit.de/) for unit tests.

## Coding standards (ECS)

Run Easy Coding Standard (ECS) and apply fixes.

```bash
composer run easy-coding-standard
```

## Dependency definition check

Verify that runtime dependencies are correctly declared in `composer.json`.

```bash
composer run check-dependencies
```

## Static analysis (Psalm)

Run static analysis.

```bash
composer run psalm
```

## Unit tests (PHPUnit)

Run the full test suite.

```bash
composer run test
```

## Passing extra arguments

Composer scripts support forwarding additional arguments using `--`.

Example: run a specific PHPUnit test or filter by name.

```bash
composer run test -- --filter AssetManagerTest
```

Example: run Psalm with a different memory limit.

```bash
composer run psalm -- --memory-limit=512M
```
