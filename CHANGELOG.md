# ChangeLog

## 0.1.3 Under development

- Enh #87: Refactor `SemverUtil::class` stability normalization logic (@terabytesoftw)
- Bug #104: Add support for PHP 8.4 (@terabytesoftw)
- Enh #105: Update dependencies in `composer.lock` (@terabytesoftw)
- Enh #106: Update composer dependencies for compatibility with newer versions (@terabytesoftw)
- Bug #107: Force 4-space indentation when updating `package.json` (@terabytesoftw)
- Bug #108: Restore working directory after running the asset manager (@terabytesoftw)
- Bug #109: Respect `root-package-json-dir` for `package.json` read/write (@terabytesoftw)
- Bug #110: Preserve nested empty arrays when rewriting `package.json` (@terabytesoftw)
- Bug #111: Throw `RuntimeException` class on asset/JSON `I/O` failures (@terabytesoftw)
- Bug #112: Update `README.md` and add development and testing documentation (@terabytesoftw)
- Bug #113: Fix PHP `8.4` nullable type deprecation warnings in tests (@terabytesoftw)
- Bug #114: Fix PHP `8.5` deprecation of `setAccessible()` in `ReflectionProperty` class (`@terabytesoftw`)
- Bug #115: Update CI workflows and apply automated refactors (@terabytesoftw)
- Bug #116: Update `LICENSE` and `composer.json` (@terabytesoftw)
- Bug #117: Raise PHPStan level to `5` (@terabytesoftw)

## 0.1.2 June 10, 2024

- Bug #64: Update docs, `composer.lock` and change directory in `Solver.php` (@terabytesoftw)
- Enh #63: Add the ability to specify a custom directory for `package.json` (@terabytesoftw)
- Bug #69: Add `funding.yml` file (@terabytesoftw)

## 0.1.1 April 4, 2024

- Enh #50: Add `BunManager` class to manage the `Bun` instances (@terabytesoftw)
- Enh #52: Add file lock `yarn.lock` for `Bun` and update `README.md` (@terabytesoftw)

## 0.1.0 January 21, 2024

- Initial release.
