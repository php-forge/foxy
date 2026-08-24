# Getting started

1. [Introduction](#introduction)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Manager selection](#manager-selection)
5. [Next steps](#next-steps)

## Introduction

Foxy is a Composer plugin that aggregates frontend dependencies declared by installed Composer packages. It creates
local package representations and lets Bun, npm, pnpm, or Yarn resolve and install those dependencies with its native
solver.

An embedded `package.json` should be treated as an independently versioned frontend package. Foxy uses the Composer
package version only when the embedded package does not declare its own version.

## Requirements

| Requirement      | Supported version or value               |
| ---------------- | ---------------------------------------- |
| PHP              | 8.3 or later                             |
| Composer         | 2.10.2 or later                          |
| Frontend manager | Bun, npm, pnpm, or Yarn                  |
| Node.js          | Required by npm, pnpm, and Yarn          |
| Git              | Required only for Git-based dependencies |

## Installation

Composer plugins execute code during Composer operations. Authorize Foxy explicitly before installing it:

```bash
composer config allow-plugins.php-forge/foxy true
composer require php-forge/foxy:^0.3
```

The plugin is installed in the configured Composer vendor directory, normally `vendor/php-forge/foxy`.

## Manager selection

Set `config.foxy.manager` to `bun`, `npm`, `pnpm`, or `yarn` when reproducible manager selection is required. When the
option is omitted, Foxy looks for one recognized native lockfile and then for an available manager executable. Multiple
recognized lockfiles require explicit selection.

Commit the selected manager's native lockfile and use the same explicit manager in local development and CI.

## Next steps

- ⚙️ [Configuration reference](config.md)
- 💡 [Usage guide](usage.md)
- 🔌 [Events reference](events.md)
- ❓ [Frequently asked questions](faqs.md)
- ⬆️ [Upgrade guide](../UPGRADE.md)
- 🧪 [Testing guide](testing.md)
- 📖 [README](../README.md)
