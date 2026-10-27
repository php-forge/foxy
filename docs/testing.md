# Testing

This package provides a consistent set of [Composer](https://getcomposer.org/) scripts for local validation.

Tool references:

- [Actionlint](https://github.com/rhysd/actionlint) for GitHub Actions workflow linting.
- [Codespell](https://github.com/codespell-project/codespell) for spelling checks.
- [Composer Require Checker](https://github.com/maglnet/ComposerRequireChecker) for dependency definition checks.
- [Easy Coding Standard (ECS)](https://github.com/easy-coding-standard/easy-coding-standard) for coding standards.
- [EditorConfig Checker](https://github.com/editorconfig-checker/editorconfig-checker) for EditorConfig compliance.
- [Gitleaks](https://github.com/gitleaks/gitleaks) for secret scanning.
- [Infection](https://infection.github.io/) for mutation testing.
- [Markdownlint-cli2](https://github.com/DavidAnson/markdownlint-cli2) for Markdown linting.
- [PHPStan](https://phpstan.org/) for static analysis.
- [PHPUnit](https://phpunit.de/) for unit tests.
- [Prettier](https://prettier.io/) for Markdown and YAML formatting checks.
- [Rector](https://github.com/rectorphp/rector) for automated refactoring.
- [Yamllint](https://github.com/adrienverge/yamllint) for YAML linting.
- [Zizmor](https://github.com/zizmorcore/zizmor) for GitHub Actions security audits.

## Automated refactoring (Rector)

Run Rector to apply automated code refactoring. This command modifies files.

```bash
composer rector
```

Validate Rector without modifying files.

```bash
composer rector-check
```

## Coding standards (ECS)

Check coding standards without modifying files.

```bash
composer ecs-check
```

Apply coding standard fixes. This command modifies files.

```bash
composer ecs
```

## Dependency definition check

Verify that runtime dependencies are correctly declared in `composer.json`.

```bash
composer check-dependencies
```

## Mutation testing (Infection)

Run mutation testing.

```bash
composer mutation
```

Run mutation testing with static analysis enabled.

```bash
composer mutation-static
```

## Repository quality checks

Make actionlint (and its ShellCheck helper), codespell, EditorConfig Checker, markdownlint-cli2, Prettier and yamllint
available on `PATH`. Run all repository quality checks:

```bash
composer quality
```

Each tool can also be run separately:

```bash
composer actionlint
composer codespell
composer editorconfig-checker
composer markdownlint
composer prettier
composer yamllint
```

The codespell, EditorConfig Checker and Markdownlint configurations are in `.github/linters`. Prettier uses
`.prettierignore` as its ignore path; yamllint checks `.github`.

## Static analysis (PHPStan)

Run static analysis.

```bash
composer static
```

## Unit tests (PHPUnit)

Run the full test suite.

```bash
composer tests
```

## Workflow security checks

Make zizmor and Gitleaks available on `PATH`. Run both checks, or either one separately:

```bash
composer security
composer zizmor
composer gitleaks
```

Zizmor uses the pedantic persona and may need GitHub API access for online audits. To request an offline audit:

```bash
composer zizmor -- --offline
```

Report offline limitations and hosted CI results separately. Gitleaks scans Git history with the repository's
configuration. Review findings instead of disabling the checks.

## Passing extra arguments

Composer scripts support forwarding additional arguments using `--`.

Run PHPUnit with code coverage report generation.

```bash
composer tests -- --coverage-html code_coverage
```

Run PHPStan with a different memory limit.

```bash
composer static -- --memory-limit=512M
```

## Next steps

- 📚 [Getting started](index.md)
- 💡 [Usage guide and examples](usage.md)
- 🤖 [AI agents guide](agents.md)

[Back to documentation](../README.md#documentation)
