# Frequently asked questions

## Which PHP and Composer versions are required?

Foxy 0.3 requires PHP 8.3 or later and Composer 2.10.2 or later. See the
[requirements](index.md#requirements).

## Why use Foxy instead of documenting frontend dependencies manually?

A PHP library can ship a `package.json`, but Composer does not merge that file into the consuming application. Without
automation, every application must copy dependency names and constraints manually and keep them synchronized with each
library release.

Foxy creates local frontend package representations for eligible Composer dependencies, merges them into the project
`package.json`, and delegates version solving and installation to Bun, npm, pnpm, or Yarn.

## How does package activation work?

An installed Composer package is eligible when it contains the expected package definition and at least one of these
conditions applies:

- It requires `php-forge/foxy`.
- It declares `extra.foxy=true`.
- The root project enables it through `config.foxy.enable-packages`.

The root project can also explicitly exclude a package through `enable-packages`.

## How are local frontend packages named and stored?

The Composer name is converted to the `@composer-asset/` scope. For example, `acme/theme` becomes
`@composer-asset/acme--theme`.

Mock package definitions are stored under `<vendor-dir>/php-forge/composer-asset/` by default. The project
`package.json` refers to those definitions with local `file:` dependencies. Configure `composer-asset-dir` to use a
different location.

## How does Foxy select a frontend manager?

Set `config.foxy.manager` to `bun`, `npm`, `pnpm`, or `yarn` for deterministic selection. When it is omitted, Foxy first
looks for one recognized native lockfile and then for an available manager executable. Multiple recognized lockfiles
require explicit selection.

Explicit selection and a committed native lockfile are recommended for CI.

## Why is a dependency's package.json not detected?

Check the following:

1. The package uses one of the documented activation methods.
2. Its `package.json` exists at the package root or configured Foxy root.
3. The root application's `enable-packages` configuration does not exclude it.
4. The selected frontend manager is installed and allowed by its configured version constraint.

Composer must run before a standalone frontend manager command because Foxy creates the local package representations
during Composer install and update operations.

## What happens when the frontend manager fails?

When the manager throws or returns any non-zero status, Foxy reports the error and, by default, restores the previous
project `package.json`, Composer lock data, and installed Composer dependencies. The original manager error is rethrown
when restoration succeeds. If Composer restoration also fails, the rollback error retains the original error as its
previous exception.

Set `fallback-asset` or `fallback-composer` to `false` only when retaining partial state is intentional. A Composer
rollback installer failure is reported as a `RuntimeException` rather than being hidden.

The Composer fallback does not restore root `composer.json` bytes that Composer may change before Foxy takes its
snapshot. After a failed `composer require` or `composer remove`, inspect that file and revert unintended constraint
changes manually.

## Why does Foxy do nothing during Composer --dry-run?

Composer does not install, update, or remove packages during a dry run, so Foxy cannot build an accurate map from the
resulting installed packages. Run the real Composer operation to validate the complete Composer and frontend manager
operation; enabled fallbacks restore their captured state when asset solving fails.

## Can Foxy update package.json without installing frontend dependencies?

Yes. Set `config.foxy.run-asset-manager=false`. Foxy will update the package definition but skip the external manager
validation and command.

## Why are a dependency's scripts or devDependencies not copied?

Foxy mock packages contain only metadata needed for runtime dependency resolution and compatibility checks. Omitting
lifecycle scripts, executable definitions, `devDependencies`, and unrelated publishing metadata prevents an installed
Composer dependency from injecting those behaviors into the root frontend install.

Publish built assets with the Composer package, or configure intentional build commands and development dependencies
in the consuming application's own `package.json`.

## Why is my custom composer-asset-dir rejected?

Foxy deletes and rebuilds its mock package directory on every solve. It refuses filesystem, project, and vendor roots;
paths that contain either protected root; symbolic-link directories; and existing non-empty custom directories without
Foxy's `.foxy-managed` marker.

If the directory predates Foxy 0.3, verify that it contains only generated mock data, remove its contents, and rerun
Composer. Foxy accepts an empty custom directory and creates the ownership marker automatically. See
[Mock package directory](config.md#mock-package-directory).

## How can the PHP memory limit be increased?

See Composer's [memory limit troubleshooting guide](https://getcomposer.org/doc/articles/troubleshooting.md#memory-limit-errors).

## How is Foxy related to Fxp Composer Asset Plugin?

[Fxp Composer Asset Plugin](https://github.com/fxpio/composer-asset-plugin) made frontend packages available through
Composer's solver. Foxy takes the opposite approach: Composer packages expose frontend definitions and a native
frontend manager performs frontend version solving and installation. Foxy is not backward compatible with Fxp Composer
Asset Plugin configuration.

## Next steps

- 📚 [Getting started](index.md)
- ⚙️ [Configuration reference](config.md)
- 💡 [Usage guide](usage.md)
- 🔌 [Events reference](events.md)
- 🧪 [Testing guide](testing.md)
- ⬆️ [Upgrade guide](../UPGRADE.md)
- 📖 [README](../README.md)
