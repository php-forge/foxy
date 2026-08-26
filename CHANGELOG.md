# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.0 Under development

- docs: update command syntax in `development.md` and `testing.md` for clarity and consistency.
- fix: remove the unnecessary `src` argument from the Rector command in `composer.json`.
- feat: prepare Foxy `0.3` for PHP `8.3`, faster execution, safer fallbacks, updated tooling, and clearer docs.
- fix: preserve plugin self-updates and clarify framework-agnostic Composer application support.
- feat!: require Bun `^1.4.0`, npm `>=10.9.8`, pnpm `^11.23.0`, or Yarn `^4.18.0` and remove legacy manager support.
- fix: run manager commands in the configured root directory without changing the PHP working directory, and prevent manager probes and npm dependency cleanup when manager execution is disabled.
- feat!: add secure frontend audits with CVE reporting, CI formats, and strict npm, pnpm, Yarn, and Bun validation.
- perf: write generated Composer asset manifests directly without first copying their source files.
- fix: detect generated asset manifest write failures before running the frontend manager.
- docs: clarify that manager execution does not change the PHP process working directory.

## 0.2.0 January 24, 2026

- fix: enforce four-space indentation when updating `package.json`.
- fix: restore the working directory after running the asset manager.
- fix: respect `root-package-json-dir` for `package.json` read and write operations.
- fix: preserve nested empty arrays when rewriting `package.json`.
- fix: throw `RuntimeException` on asset and JSON I/O failures.
- docs: update `README.md` and add development and testing documentation.
- fix: resolve PHP 8.4 nullable type deprecation warnings in tests.
- fix: resolve the PHP 8.5 deprecation of `ReflectionProperty::setAccessible()`.
- ci: update CI workflows and apply automated refactors.
- chore: update `LICENSE` and `composer.json`.
- chore: raise the PHPStan level to 5.
- chore: add the `phpdoc_param_order` rule and update namespace references in `rector.php`.
- chore: add `php-forge/coding-standard` to development dependencies for code quality checks.
- docs: clean up event documentation in `FoxyEvents`.

## 0.1.3 March 13, 2025

- refactor: simplify `SemverUtil` stability normalization logic.
- fix: update nullable type declarations for PHP 8.4 compatibility.
- chore: update dependencies in `composer.lock`.
- chore: update Composer dependencies for compatibility with newer versions.

## 0.1.2 June 10, 2024

- fix: update documentation, `composer.lock`, and working directory handling in `Solver`.
- feat: support a custom asset directory.
- chore: add `funding.yml`.

## 0.1.1 April 4, 2024

- feat: add `BunManager` to manage Bun instances.
- feat: add `yarn.lock` handling for Bun and update `README.md`.

## 0.1.0 January 21, 2024

- feat: initial release.
