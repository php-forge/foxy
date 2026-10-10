# Upgrading Foxy

## Upgrading from 0.3.x to 0.4.x

Foxy 0.4 makes every manager auditable, resolves the frontend manager on first use, moves the asset rollback into the
solver, and removes utilities that Composer `^2.10` made redundant. Projects that only configure Foxy through
`composer.json` need no change beyond the constraint; authors of custom managers, audit parsers, or CVE resolvers must
follow the sections below.

### Composer constraint

```bash
composer require php-forge/foxy:^0.4 --with-all-dependencies
```

`symfony/console` `^8.0` is accepted beside `^7.4`; PHP 8.3 projects keep resolving `7.4`.

### Every manager audits

`Foxy\Audit\AuditableAssetManagerInterface` and `Foxy\Asset\AbstractAuditableAssetManager` were removed.
`AssetManagerInterface` now declares `audit(bool $noDev): AuditProcessResult` and
`getAuditParser(): AuditParserInterface`, and `AbstractAssetManager` carries the process-based implementation that the
former base class held (`getAuditCommand()`, `getAuditEnvironment()`, `validateAuditConfiguration()`). Custom managers
that extended `AbstractAuditableAssetManager` extend `AbstractAssetManager` instead and add `getAuditParser()`; direct
`AssetManagerInterface` implementations must add both methods, throwing a `RuntimeException` from `audit()` when they
cannot audit. `Foxy\Audit\AuditParserFactory` was removed: the runner asks the manager for its parser.
`Foxy\Audit\Parser\BunAuditParser` now extends the new `AbstractBulkAdvisoryParser`, shared with the native manager's
`NativeAuditParser`; the message case `Message::AUDIT_BUN_PACKAGE_ADVISORIES_REQUIRED` is now
`AUDIT_BULK_PACKAGE_ADVISORIES_REQUIRED`, and `AUDIT_PARSER_UNSUPPORTED_MANAGER` and `FOXY_AUDIT_UNSUPPORTED_MANAGER`
were removed.

The `native` manager now supports `composer foxy:audit` through the registry's bulk advisories endpoint; `--no-dev`
is rejected for it, as for Deno, because the native manager installs development dependencies together with the
others. `Foxy\Asset\NativeManager` takes the `NpmRegistryInterface` in its constructor, and `NpmRegistryInterface`
gained `getAdvisories(array $versions): string` and `prefetchTarballs(array $packages): void`; custom registries must
implement both.

### CVE lookups resolve in batches

`Foxy\Audit\CveResolverInterface::resolve(string): CveResolution` was replaced by
`resolveMany(array $ghsaIds): array`, which returns a `CveResolution` or a `Throwable` per identifier.
`GitHubAdvisoryCveResolver` issues its requests concurrently through Composer's HTTP downloader and therefore needs the
async-enabled downloader (`Composer::getLoop()->getHttpDownloader()`), which `composer foxy:audit` already uses.
`CveEnricher` keeps its constructor and `enrich()` signature.

### Manager selection timing

Foxy no longer probes manager executables when Composer loads the plugin. The manager is resolved when the first
install, update, or `composer foxy:audit` needs it, so Composer commands that do none of these (`about`, `show`,
`validate`, ...) never run `<manager> --version`. Selection errors (an unknown `manager`, several native lockfiles, a
lockfile whose executable is missing, or no manager at all) are reported by the first command that needs the manager
instead of by every Composer command; `composer foxy:audit` reports them with exit status `2`.

### Manager-scoped configuration

`Foxy\Config\Config::setResolvedManager()` was removed. Probing or validating a manager no longer changes the shared
`Config`; `Config::forManager(string $manager): static` returns a copy whose `manager-*` map entries resolve for that
manager, and `AbstractManifestAssetManager::getManagerConfig()` (protected) gives each built-in manager its own view.
Custom managers that called `setResolvedManager()` remove the call and read `manager-*` keys through
`getManagerConfig()`.

### Default commands in `AbstractAssetManager`

`getInstallCommand()`, `getUpdateCommand()`, and `getVersionCommand()` are no longer abstract. They default to
`<binary> install`, `<binary> update`, and `<binary> --version`, where the binary comes from the new protected
`getDefaultBinary(): string` hook, which returns `getName()`. Custom managers whose executable differs from their name
override `getDefaultBinary()`; managers that implement the three methods keep working unchanged.

### Asset rollback belongs to the solver

`AssetManagerInterface::setFallback()` was removed, together with the fallback constructor argument, the protected
`$fallback` property, and `restoreAfterFailure()` of `AbstractAssetManager`. `Foxy\Solver\Solver` takes the asset
fallback as its last constructor argument and restores the manifest when the dependency merge or the manager run
fails, in the same cases as before; managers no longer restore anything.

### Composer rollback events

The rollback `Installer` is now built with a private event dispatcher that has no listeners and runs no scripts, so
command and autoload events of the rollback never reach the project's scripts or other plugins. Package events
(`pre-package-install`, `post-package-update`, ...) are still dispatched by Composer's shared installation manager
during a rollback, so root scripts and plugin listeners bound to those events run; projects that must not run them
during a rollback should guard those scripts.

### Removed utilities

`Foxy\Util\LockerUtil` was removed; the Composer fallback reads the lock through `Composer\Factory::getLockFile()`.
`Foxy\Util\ComposerUtil::getInitEventName()` was removed; use `Composer\Installer\InstallerEvents::PRE_OPERATIONS_EXEC`.
`Foxy\Util\PackageUtil::convertLockAlias()` and the `$convertAliases` parameter of `loadLockPackages()` were removed.
`Foxy\Json\JsonFile::getIndent()`, `Foxy\Json\JsonFormatter::getIndent()`, and the constant
`JsonFormatter::INDENT_REGEX` were removed.

## Upgrading from 0.2.x to 0.3.x

Foxy 0.3 raises the runtime baseline and aligns its documented integration target with the PHP 8.3 development line.

### Runtime requirements

Before updating, ensure the environment provides:

- PHP 8.3 or later.
- Composer 2.10.2 or later.
- One supported frontend manager for automatic manager execution or explicit security audits: Bun `^1.4.0`, Deno `^2.9.7`, npm `>=10.9.8`, pnpm `^11.23.0`, or Yarn `^4.18.0`.
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
`isValidForUpdate()` method. Custom managers that support `composer foxy:audit` must extend
`AbstractAuditableAssetManager` and implement `getAuditCommand(bool $noDev): string`. Foxy currently normalizes only
the report schemas and manager names of its five built-in managers: Bun, Deno, npm, pnpm, and Yarn. Arbitrary custom
managers are not supported by `composer foxy:audit`. `AbstractAssetManager` subclasses and direct
`AssetManagerInterface` implementations that do not implement Foxy's auditable manager contract remain usable for
asset solving, but the audit command rejects them because they do not support security audits.

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
