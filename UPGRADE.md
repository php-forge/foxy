# Upgrading Foxy

This file lists, for each release, the changes that need an action from you or that change what you see when Foxy
runs. Features that need neither are described in the [documentation](docs/index.md) and the [changelog](CHANGELOG.md).

## Upgrading from 0.3.x to 0.4.x

### Update the constraint

```bash
composer require php-forge/foxy:^0.4 --with-all-dependencies
```

Foxy 0.4 accepts `symfony/console` `^7.4 || ^8.0`. Console 8 requires PHP 8.4, so a project on PHP 8.3 keeps 7.4;
`--with-all-dependencies` lets Composer move the shared Symfony packages when the project allows it.

### The manager is selected on first use

Foxy no longer runs `<manager> --version` each time Composer loads the plugin. The manager is selected when an
install, an update, or `composer foxy:audit` needs it:

- Composer commands that need no manager (`about`, `show`, `validate`, ...) run without probing one and never fail
  because a manager is missing or unsupported.
- An unknown `manager` value, several recognized lockfiles, a lockfile whose manager is not installed, or no manager at
  all are reported by the first command that needs the manager; `composer foxy:audit` reports them with exit status
  `2`.

A CI job that relied on any Composer command failing early because of a missing manager must run `composer install`
or `composer foxy:audit` to keep that check.

### Native manager

- `composer foxy:audit` audits the `native` manager through the registry's bulk advisories endpoint
  (`POST /-/npm/v1/security/advisories/bulk`). `--no-dev` is rejected, as for Deno, because the native manager
  installs the development dependencies together with the others. A private registry must implement that endpoint
  (Verdaccio: enable its `audit` middleware); otherwise the audit fails with status `2`.
- The install directory gains a hidden `.foxy-installed.json` file that records what Foxy installed. The first install
  after the upgrade extracts every registry package once and writes it; later installs keep the registry packages
  whose directory and recorded entry still match `foxy.lock`, copy the `file:` packages again, and report only what
  changed, printing `Nothing to install, update or remove` when nothing did. Run Composer with `-v` to list the kept
  and refreshed packages and `-vv` to follow the registry traffic. Delete the file to extract every registry package
  again.

### Rollbacks

- The `package.json` restore that follows a failed dependency merge or manager run now happens before the
  `foxy.post-solve` event. A `post-solve` listener that throws no longer triggers it; only the Composer rollback runs.
- The Composer rollback runs on a private event dispatcher: command and autoload events never reach the project's
  scripts or other plugins. Package events (`pre-package-install`, `post-package-update`, ...) are still dispatched by
  Composer's installation manager and do reach them, where 0.3 skipped them. A root script bound to a package event
  that must not run during a rollback has to guard itself.

### PHP API

The classes that custom managers, audit parsers, and CVE resolvers extend changed in this release. A project that
configures Foxy through `composer.json` is not affected.

## Upgrading from 0.2.x to 0.3.x

### Runtime requirements

Before updating, make sure the environment provides:

- PHP 8.3 or later. PHP 8.1 and 8.2 are not supported.
- Composer 2.10.2 or later.
- One supported frontend manager when Foxy runs it or audits with it: Bun `^1.4.0`, Deno `^2.9.7`, npm `>=10.9.8`,
  pnpm `^11.23.0`, or Yarn `^4.18.0`. npm needs a Node.js version its release supports, pnpm needs Node.js
  `>=22.13.0`, and Yarn needs Node.js `>=18.12.0` on a release that still receives security updates.

Earlier manager versions are rejected. The `manager-version` setting can narrow the built-in constraint for a
project but cannot widen it. The Ctype and Mbstring extensions are no longer required.

### Update the constraint and authorize the plugin

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

A library that keeps Foxy in `require-dev` updates that constraint to `^0.3` as well.

### Choose the manager

Foxy selects the manager from a single recognized lockfile or from an available executable. Several recognized
lockfiles require an explicit choice. For predictable upgrades and CI runs, configure the manager and commit its
lockfile:

```json
{
  "config": {
    "foxy": {
      "manager": "npm"
    }
  }
}
```

Remove stale lockfiles of other managers before the first Composer operation with Foxy 0.3, then bring the manager
to a supported version:

- **npm**: `npm --version` must satisfy `>=10.9.8`; run `npm install` and commit the `package-lock.json` changes. npm
  10.9.8 requires Node.js `^18.17.0 || >=20.5.0`; see the [npm documentation](https://docs.npmjs.com/cli/) for the
  release in use.
- **pnpm**: pnpm 11 requires Node.js 22. Upgrade Node.js, then run `pnpm self-update 11`,
  `pnpx codemod run pnpm-v10-to-v11`, and `pnpm install`; `pnpm --version` must satisfy `^11.23.0`. Review the codemod
  result with the [pnpm migration guide](https://pnpm.io/migration) and commit `pnpm-lock.yaml` and the configuration
  changes.
- **Bun**: run `bun upgrade`; `bun --version` must satisfy `^1.4.0`. Foxy recognizes only the text lockfile, so convert
  a legacy `bun.lockb` without changing the resolved versions with
  `bun install --save-text-lockfile --frozen-lockfile --lockfile-only`, commit `bun.lock`, and delete `bun.lockb`. A
  project that relied on `yarn.lock` for Bun's automatic selection generates `bun.lock` or sets `manager` to `bun`.
- **Yarn**: Yarn 1, 2, and 3 are not supported. Install Corepack (`npm install --global corepack`, `corepack enable`),
  run `yarn set version 4.x` and `yarn install`; `yarn --version` must satisfy `^4.18.0`. Commit the `packageManager`
  field, `yarn.lock`, and the Yarn configuration. The [installation guide](https://yarnpkg.com/getting-started/install),
  the [migration guide](https://yarnpkg.com/migration/guide), and the
  [Yarn 4 release notes](https://yarnpkg.com/blog/release/4.0) document the configuration and command changes,
  including the move from `.npmrc` or `.yarnrc` to `.yarnrc.yml`.

### Embedded package metadata and paths

Foxy copies into its local package representation only the metadata it needs to resolve dependencies and check
compatibility. Lifecycle `scripts`, `devDependencies`, executable definitions, entry points, and publishing metadata of
an installed Composer dependency are no longer propagated. A library moves its runtime requirements to
`dependencies`, `optionalDependencies`, or `peerDependencies`, ships prebuilt assets with the Composer package, or
asks the consuming application to declare its own build steps in `package.json`.

A `root-package-json-dir` of a library must resolve inside that library's installation directory; a value that leaves
it is rejected with a `RuntimeException`. Foxy regenerates the local `file:` references relative to the consuming
`package.json`: review a project with a custom root after the first update and commit the resulting manifest changes.

Foxy resets `composer-asset-dir` recursively and therefore checks that it owns the directory. Filesystem, project,
and vendor roots, directories containing either, and symbolic links are rejected. An existing, non-empty custom
directory must contain Foxy's `.foxy-managed` marker: before upgrading, verify that the directory holds only generated
Foxy data, empty it, and let the first Foxy 0.3 run recreate and mark it. A new or empty directory is marked
automatically.

### Fallbacks and disabled modes

Both fallbacks stay enabled by default; a project that disabled one should confirm that keeping partial state after a
manager error is still intended.

The asset rollback runs when package merging or the manager fails. The Composer rollback runs after every solve
exception and every non-zero manager result. When the Composer restore succeeds, Foxy rethrows the original error;
when it fails too, Foxy reports the rollback error with the original failure as its previous exception. The rollback
does not run project scripts, and a non-zero result of the rollback installer is reported instead of being treated as a
success.

The rollback snapshot of `composer.lock` and the vendor directory is taken at Composer's `pre-operations-exec` event.
The root `composer.json` is not part of it, and `composer require` or `composer remove` may already have changed it:
inspect and, when necessary, revert the root manifest after a failed operation.

`enabled: false` bypasses manager discovery, fallback snapshots, package merging, and manager execution.
`run-asset-manager: false` keeps package merging but skips manager probing, version validation, execution, and the
npm cleanup of `node_modules/@composer-asset/*`. `composer foxy:audit` still validates and invokes the selected
manager in that mode; it never installs, updates, or repairs dependencies.
