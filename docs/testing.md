# Testing

## Checking dependencies

This package uses [composer-require-checker](https://github.com/maglnet/ComposerRequireChecker) to check if all dependencies are correctly defined in `composer.json`.

To run the checker, execute the following command:

```shell
composer run check-dependencies
```

## Mutation testing

Mutation testing is checked with [Infection](https://infection.github.io/). To run it:

```shell
composer run mutation
```

## Static analysis

The code is statically analyzed with [Psalm](https://psalm.dev/). To run static analysis:

```shell
composer run psalm
```

## Unit tests

The code is tested with [PHPUnit](https://phpunit.de/). To run tests:

```
composer run test
```
