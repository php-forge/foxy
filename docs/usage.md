# Usage

## Application setup

Authorize and require the plugin in the application:

```json
{
  "require": {
    "php-forge/foxy": "^0.3"
  },
  "config": {
    "allow-plugins": {
      "php-forge/foxy": true
    },
    "foxy": {
      "manager": "npm"
    }
  }
}
```

The project may provide its own frontend dependencies in `package.json`:

```json
{
  "private": true,
  "dependencies": {
    "@foo/bar": "^1.2.0"
  }
}
```

During Composer install and update operations, Foxy merges eligible Composer package assets into this file and runs
the selected frontend manager. Existing non-Foxy dependencies are preserved.

## Security auditing

Run an explicit frontend dependency audit from Composer:

```bash
composer foxy:audit
```

The command requires the selected manager's native lockfile, validates the manager version, and asks the manager for a
machine-readable security report. It reads the lockfile and does not run an install, update, fix, or fallback. Foxy
supports npm audit report version 2 starting with npm 10.9.8 and the current report schemas emitted by pnpm 11, Yarn 4,
and Bun 1.4; legacy report formats are rejected instead of being interpreted heuristically.

Foxy reports every advisory returned by the manager. `--audit-level` controls only the CI exit threshold:

```bash
composer foxy:audit --audit-level=high
```

Valid levels are `low`, `moderate`, `high`, and `critical`; the default is `low`. Use `--no-dev` to audit only the
production dependency graph:

```bash
composer foxy:audit --no-dev
```

For npm workspace roots, Foxy explicitly includes every workspace and the root package so ambient npm workspace
selection cannot silently narrow the audited lock graph.

Foxy also overrides pnpm and Yarn settings that could silently filter the requested dependency graph or known
advisories. Bun 1.4 cannot safely reset every inherited scope setting while retaining project registry and
authentication configuration. A Bun audit therefore returns status `2` when a loaded `.npmrc` or `bunfig.toml` excludes
a dependency type that the requested audit must cover. Development-only restrictions are accepted with `--no-dev`;
optional and peer dependency restrictions are not. Bun configuration must be UTF-8 and use canonical `[install]` table
syntax with single-line values so the preflight can verify it without heuristics. Restrictive declarations are rejected
even when another setting would override them.

### Audit output formats

Select `table`, `plain`, `json`, or `summary` with `--format` or `-f`:

```bash
composer foxy:audit --format=table
composer foxy:audit --format=plain
composer foxy:audit --format=json
composer foxy:audit --format=summary
```

The default table identifies the package, severity, advisory ID, CVE, vulnerable range, and advisory title. The JSON
document has `schema_version: 1` and is suitable for CI artifacts. Diagnostic and CVE lookup warnings are written to
standard error so JSON on standard output remains valid.

Native npm registry audit data normally identifies advisories by a numeric ID and GHSA rather than a CVE. Unless
`--no-cve` or `--format=summary` is used, Foxy resolves each unique public GHSA once through the
[GitHub global security advisories API](https://docs.github.com/en/rest/security-advisories/global-advisories). A
failed optional lookup is reported as `unavailable` and does not discard the native finding or change its audit exit
status.

Bun can skip packages from a registry that does not answer its audit request while still returning a successful native
status. Foxy therefore treats any diagnostic emitted by `bun audit --json` as an unreliable partial report and returns
status `2` instead of allowing CI to pass on incomplete data.

### Audit exit status and CI

The exit status is stable across all supported managers:

| Status | Meaning                                                                                                 |
| -----: | ------------------------------------------------------------------------------------------------------- |
|      0 | The audit succeeded and no advisory met `--audit-level`.                                                |
|      1 | The audit succeeded and at least one advisory met `--audit-level`.                                      |
|      2 | A configuration, manager, lockfile, execution, or report validation failure prevented a reliable audit. |

Gate a production build on high and critical findings while retaining a compact log:

```bash
composer foxy:audit --no-dev --audit-level=high --format=summary
```

The explicit command still runs when `run-asset-manager` is `false`; that setting controls automatic install and
update execution, not a user-requested audit. Foxy must be enabled, the selected binary must satisfy its supported
version constraint, and the matching native lockfile must exist.

## Library setup

A Composer library becomes eligible for Foxy when it contains the selected manager's package definition and uses one
of the activation methods below.

### Required Foxy dependency

Use a runtime dependency when every consumer of the library must process its frontend package:

```json
{
  "require": {
    "php-forge/foxy": "^0.3"
  }
}
```

### Optional development dependency

Use a development dependency when Foxy is optional for library development:

```json
{
  "require-dev": {
    "php-forge/foxy": "^0.3"
  }
}
```

The consuming application must still require and authorize Foxy because dependencies of a library are not installed
from its `require-dev` section.

### Activation through Composer extra

A library can declare itself eligible without adding a Foxy dependency:

```json
{
  "extra": {
    "foxy": true
  }
}
```

The consuming application must require and authorize Foxy in this case as well.

## Package definitions in a subdirectory

When a library stores its frontend package outside its root, declare the relative directory in that library's
`composer.json`:

```json
{
  "config": {
    "foxy": {
      "root-package-json-dir": "resources"
    }
  }
}
```

Foxy then reads `resources/package.json` from the installed library. In the root application, the same option controls
where Foxy reads and writes the project `package.json` and where it runs the frontend manager.

The embedded manifest must resolve inside the installed library's Composer directory. Foxy rejects a
`root-package-json-dir` value that resolves outside that boundary.

## Embedded package metadata

Foxy creates a local package representation for each eligible Composer dependency. It copies only metadata required
for dependency resolution and compatibility checks. Package lifecycle `scripts`, `devDependencies`, executable
definitions, entry points, and unrelated publishing metadata are not propagated to the consuming application.

Library authors should declare runtime frontend requirements in `dependencies`, `optionalDependencies`, or
`peerDependencies`. Any build step needed to publish those assets should run before the Composer package is released,
or be configured explicitly by the consuming application.

## Manually enabling or disabling packages

The root application can use `config.foxy.enable-packages` to include a package that does not declare Foxy or to
exclude a package that does. See [Package selection](config.md#package-selection).

## Behavior and guarantees

- Foxy-managed dependencies use the `@composer-asset/` scope and local `file:` references.
- Existing project dependencies and development dependencies are retained.
- `package.json` writes use four-space indentation and preserve empty object and array semantics.
- `root-package-json-dir` controls project reads, writes, and manager working directory.
- Foxy restores the original working directory after manager execution.
- Asset restoration occurs when package merging or manager execution fails and `fallback-asset` is enabled.
- Composer lock and vendor restoration occurs after any solve exception or non-zero manager result when
  `fallback-composer` is enabled.
- Read, write, and remove failures are reported through exceptions instead of being silently ignored.

Composer restoration does not include the root `composer.json` bytes. Review that file after a failed
`composer require` or `composer remove` operation.

## Next steps

- 📚 [Getting started](index.md)
- ⚙️ [Configuration reference](config.md)
- 🔌 [Events reference](events.md)
- ❓ [Frequently asked questions](faqs.md)
- 🧪 [Testing guide](testing.md)
- ⬆️ [Upgrade guide](../UPGRADE.md)
- 📖 [README](../README.md)
