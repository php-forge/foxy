# ChangeLog

## 0.2.1 Under development

- Bug #121: Update command syntax in `development.md` and `testing.md` for clarity and consistency (@terabytesoftw)
- Bug #122: Update Rector command in `composer.json` to remove unnecessary 'src' argument (@terabytesoftw)

## 0.2.0 January 24, 2024

- Enh #87: Refactor `SemverUtil::class` stability normalization logic (@terabytesoftw)
- Bug #104: Update nullable type hints for method parameters and properties for PHP 8.4 deprecated RFC nullable (@terabytesoftw)
- Enh #105: Update dependencies in `composer.lock` (@terabytesoftw)
- Enh #106: Update composer dependencies for compatibility with newer versions (@terabytesoftw)
- Bug #107: Force 4-space indentation when updating `package.json` (@terabytesoftw)
- Bug #108: Restore working directory after running the asset manager (@terabytesoftw)
- Bug #109: Respect `root-package-json-dir` for `package.json` read/write (@terabytesoftw)
- Bug #110: Preserve nested empty arrays when rewriting `package.json` (@terabytesoftw)
- Bug #111: Throw `RuntimeException` class on asset/JSON `I/O` failures (@terabytesoftw)
- Bug #112: Update `README.md` and add development and testing documentation (@terabytesoftw)
- Bug #113: Fix PHP `8.4` nullable type deprecation warnings in tests (@terabytesoftw)
- Bug #114: Fix PHP `8.5` deprecation of `setAccessible()` in `ReflectionProperty` class (@terabytesoftw)
- Bug #115: Update CI workflows and apply automated refactors (@terabytesoftw)
- Bug #116: Update `LICENSE` and `composer.json` (@terabytesoftw)
- Bug #117: Raise PHPStan level to `5` (@terabytesoftw)
- Bug #118: Add `phpdoc_param_order` rule and update namespace references in `rector.php` (@terabytesoftw)
- Enh #119: Add `php-forge/coding-standard` to development dependencies for code quality checks (@terabytesoftw)
- Bug #120: Clean up event documentation in `FoxyEvents` class (@terabytesoftw)

## 0.1.2 June 10, 2024

- Bug #64: Update docs, `composer.lock` and directory in `Solver.php` (@terabytesoftw)
- Enh #66: Add the ability to specify a custom directory for assets (@terabytesoftw)
- Bug #69: Add `funding.yml` file (@terabytesoftw)

## 0.1.1 April 4, 2024

- Enh #50: Add `BunManager` class to manage the `Bun` instances (@terabytesoftw)
- Enh #52: Add file lock `yarn.lock` for `Bun` and update `README.md` (@terabytesoftw)

## 0.1.0 January 21, 2024

- Enh #1: Initial commit (@terabytesoftw)
