# Upgrading Foxy

## Upgrading from 0.2.x to 0.3.x

Foxy 0.3 raises the runtime baseline and aligns its documented integration target with the PHP 8.3 development line.

### Runtime requirements

Before updating, ensure the environment provides:

- PHP 8.3 or later.
- Composer 2.10.2 or later.
- A supported Bun, npm, pnpm, or Yarn executable.
- Node.js when using npm, pnpm, or Yarn.

PHP 8.1 and 8.2 are not supported by Foxy 0.3.
The Ctype and Mbstring extensions are no longer package requirements.

### Composer constraint and plugin authorization

Update the package constraint and authorize the plugin:

```bash
composer config allow-plugins.php-forge/foxy true
composer require php-forge/foxy:^0.3 --with-all-dependencies
```

The equivalent `composer.json` configuration is:

```json
{
  "require": {
    "php-forge/foxy": "^0.3"
  },
  "config": {
    "allow-plugins": {
      "php-forge/foxy": true
    }
  }
}
```

Library authors that keep Foxy in `require-dev` should update that constraint to `^0.3` as well.

### Frontend manager selection

Foxy can select a manager automatically from one recognized native lockfile or an available executable. Multiple
recognized lockfiles require explicit selection. For predictable upgrades and CI runs, configure the manager and
commit its native lockfile:

```json
{
  "config": {
    "foxy": {
      "manager": "npm"
    }
  }
}
```

Remove stale lockfiles from other managers before the first Composer operation with Foxy 0.3.

Bun detection now uses its native `bun.lock` or legacy `bun.lockb` file instead of `yarn.lock`. Bun projects that
previously depended on `yarn.lock` for selection should generate a Bun lockfile or set `config.foxy.manager` to `bun`.

### Embedded package metadata and paths

Foxy 0.3 copies only package metadata used for runtime dependency resolution and compatibility checks into its local
frontend package representations. It no longer propagates lifecycle `scripts`, `devDependencies`, executable
definitions, entry points, or unrelated publishing metadata from an installed Composer dependency.

Library authors should move runtime requirements into `dependencies`, `optionalDependencies`, or `peerDependencies`.
Publish prebuilt assets with the Composer package, or require consuming applications to configure intentional build
steps in their own `package.json`.

An embedded manifest selected through `root-package-json-dir` must resolve inside its Composer package installation
directory. A value that traverses or resolves outside that boundary now raises a `RuntimeException`. Foxy also
regenerates local `file:` references relative to the consuming `package.json`; review custom-root projects after the
first update and commit the resulting manifest changes.

Foxy recursively resets `composer-asset-dir`, so 0.3 validates ownership before deletion. It rejects filesystem,
project, and vendor roots; directories that contain either project or vendor root; and symbolic-link asset directories.
An existing, non-empty custom directory must contain Foxy's `.foxy-managed` marker. Before upgrading a custom path,
verify that it contains only generated Foxy data, remove its contents, and let the first Foxy 0.3 run recreate and mark
the directory. New and empty custom directories are marked automatically.

### Paths and fallbacks

Review these settings when the project does not keep `package.json` at its Composer root:

- `root-package-json-dir` controls the project package path and manager working directory.
- A library can use its own `root-package-json-dir` to locate an embedded package definition.
- Mock packages use `<vendor-dir>/php-forge/composer-asset/` unless `composer-asset-dir` overrides it.

Asset and Composer fallbacks remain enabled by default. Projects that disabled either fallback should confirm that
retaining partial state after a manager error is still intended.

Asset rollback runs when package merging or manager execution fails. Composer-state rollback now runs after every solve
exception and every non-zero manager result, including negative results. If Composer restoration succeeds, Foxy
rethrows the original solve error. If restoration also fails, Foxy reports the rollback error with the original failure
as its previous exception.

Composer rollback no longer runs project scripts. A non-zero result from the rollback installer is now reported as a
`RuntimeException` instead of being treated as a successful restoration. Applications that catch manager failures
should allow for this explicit rollback failure.

Composer lock and vendor snapshots are captured at Composer's `pre-operations-exec` event. Foxy does not snapshot the
pre-command contents of the root `composer.json`, which Composer may already have changed during `composer require` or
`composer remove`. The fallback is therefore not a fully atomic Composer transaction for those commands. Inspect and,
when necessary, revert the root manifest after a failed operation.

Setting `enabled=false` now bypasses manager discovery, fallback snapshots, package merging, and manager execution.
Setting `run-asset-manager=false` retains package merging but skips manager version validation and execution.
