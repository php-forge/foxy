# Development

This document describes development workflows and maintenance tasks for this repository.

## Local setup

Install dependencies:

```bash
composer install
```

## Project scripts

The project exposes a small set of Composer scripts for validation.

| Script | Command | Purpose |
| --- | --- | --- |
| `test` | `composer run test` | Run the PHPUnit test suite |
| `psalm` | `composer run psalm` | Run static analysis |
| `easy-coding-standard` | `composer run easy-coding-standard` | Run ECS coding standards |
| `check-dependencies` | `composer run check-dependencies` | Verify declared runtime dependencies |

## Notes

- Keep changes minimal and consistent with existing code and behavior.
- Documentation and code comments are English-only.
