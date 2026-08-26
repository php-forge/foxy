# Upgrading Foxy

## Upgrading from 0.2.x to 0.3.x

Foxy 0.3 raises the runtime baseline and aligns its documented integration target with the PHP 8.3 development line.

### Runtime requirements

Before updating, ensure the environment provides:

- PHP 8.3 or later.
- Composer 2.10.2 or later.
- One supported frontend manager for automatic manager execution or explicit security audits: Bun `^1.4.0`, npm `>=10.9.8`, pnpm `^11.23.0`, or Yarn `^4.18.0`.
- For npm, use a Node.js version supported by the selected npm release.
- For pnpm, Node.js `>=22.13.0`.
- For Yarn, Node.js `>=18.12.0` on a release that still receives security updates.

PHP 8.1 and 8.2 are not supported by Foxy 0.3.
The Ctype and Mbstring extensions are no longer package requirements.
Earlier frontend manager versions are not supported. The `manager-version` setting can narrow the built-in constraint
for a project, but it cannot widen the supported range or opt an earlier manager version back in.

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

When manager execution is enabled, Foxy can select a manager automatically from one recognized native lockfile or an
available executable. Multiple recognized lockfiles require explicit selection. For predictable upgrades and CI runs,
configure the manager and commit its native lockfile:

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

#### npm migration

Ensure npm is at least 10.9.8 and regenerate the installation state:

```bash
npm --version
npm install
```

The reported npm version must satisfy `>=10.9.8`. npm 10.9.8 requires Node.js `^18.17.0 || >=20.5.0`; later npm
releases may require a newer Node.js version. Review the [npm documentation](https://docs.npmjs.com/cli/) for the
selected release and commit any `package-lock.json` changes.

#### pnpm 11 migration

pnpm 11 requires Node.js 22 or later. Upgrade Node.js first, update to the latest pnpm 11 release, apply the official
configuration codemod, and reinstall:

```bash
pnpm self-update 11
pnpm --version
pnpx codemod run pnpm-v10-to-v11
pnpm install
```

The reported pnpm version must satisfy `^11.23.0`. Review the codemod result and manual follow-ups in the
[pnpm v10 to v11 migration guide](https://pnpm.io/migration), then commit `pnpm-lock.yaml` and configuration changes.

#### Bun lockfile migration

Upgrade Bun and confirm that the result satisfies `^1.4.0`. Foxy recognizes only the text-based `bun.lock` file, so
convert a legacy `bun.lockb` without changing the resolved dependency versions:

```bash
bun upgrade
bun --version
bun install --save-text-lockfile --frozen-lockfile --lockfile-only
```

Verify and commit `bun.lock`, then delete `bun.lockb`. Bun projects that previously depended on `yarn.lock` for
automatic selection must generate `bun.lock` before running Composer or configure `config.foxy.manager` explicitly as
`bun`.

#### Yarn 4 migration

Yarn Classic 1.x, Yarn 2.x, and Yarn 3.x are not supported. Install and enable Corepack, select the latest Yarn 4
release, reinstall, and commit the resulting `packageManager`, `yarn.lock`, and Yarn configuration changes:

```bash
npm install --global corepack
corepack enable
yarn set version 4.x
yarn --version
yarn install
```

The reported Yarn version must satisfy `^4.18.0`. The
[current Yarn installation guide](https://yarnpkg.com/getting-started/install) documents the Corepack setup.
Projects migrating from Yarn Classic must also convert `.npmrc` or `.yarnrc` settings to `.yarnrc.yml` and review
renamed or removed commands. Projects upgrading from Yarn 2 or 3 should review the
[Yarn 4 release notes](https://yarnpkg.com/blog/release/4.0) before committing the regenerated installation artifacts.
The [Yarn migration guide](https://yarnpkg.com/migration/guide) documents the configuration and command changes.

### Custom manager implementations

Custom `AssetManagerInterface` implementations must add `getVersionConstraint(): string` and return their hard
supported version range as a Composer constraint. Remove implementations and calls of the obsolete
`isValidForUpdate()` method. Custom `AbstractAssetManager` subclasses must also implement
`getAuditCommand(bool $noDev): string`. Foxy currently normalizes only the report schemas and manager names of its four
built-in managers; arbitrary custom managers are not supported by `composer foxy:audit`. Direct `AssetManagerInterface`
implementations that do not implement Foxy's auditable manager contract remain usable for asset solving, but the audit
command reports that they cannot be audited reliably.

`AbstractAssetManager` subclasses inherit the simplified update eligibility based on installation state and the
`setUpdatable()` flag, concrete-version enforcement before every manager command, and version detection in the
configured `root-package-json-dir`; they must also provide `getVersionConstraint()`. Direct `AssetManagerInterface`
implementations must enforce the returned constraint in their own `validate()` and `run()` implementations.

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
Setting `run-asset-manager=false` retains package merging but skips manager binary probing, version validation,
execution, and npm cleanup of existing `node_modules/@composer-asset/*` installations. The new explicit
`composer foxy:audit` command still validates and invokes the selected manager in this mode; it never installs, updates,
or repairs dependencies.
