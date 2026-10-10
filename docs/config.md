# Configuration

Foxy reads project options from `config.foxy` in the root `composer.json`:

```json
{
  "config": {
    "foxy": {
      "manager": "npm"
    }
  }
}
```

## Configuration sources and priority

Values are resolved in this order, from highest to lowest priority:

1. Environment variables beginning with `FOXY__`.
2. The project `composer.json` file.
3. `<COMPOSER_HOME>/config.json`.
4. `<COMPOSER_HOME>/composer.json`.
5. Foxy defaults.

Global values must be placed under `config.foxy` in one of the two Composer home files. Composer does not accept
arbitrary nested options through `composer global config`, but `composer global config --editor` can be used to edit
the global file directly.

## Environment variables

Convert an option name to uppercase, replace hyphens with underscores, and prefix it with `FOXY__`. For example,
`run-asset-manager` becomes `FOXY__RUN_ASSET_MANAGER`.

Foxy accepts strings, integers, case-insensitive boolean values (`true`, `false`, `yes`, `no`, `y`, `n`, `1`, and
`0`), and JSON arrays or objects.

```bash
FOXY__ENABLED=false composer install
FOXY__MANAGER_TIMEOUT=420 composer install
FOXY__ENABLE_PACKAGES='{"foo/*":true}' composer install
```

Use shell-appropriate quoting when passing JSON. A manager-specific map is supported in Composer configuration, but a
manager-prefixed environment variable should contain the scalar value for the active manager.

## Options

| Option                    | Type             | Default                                  | Description                                                                        |
| ------------------------- | ---------------- | ---------------------------------------- | ---------------------------------------------------------------------------------- |
| `enabled`                 | boolean          | `true`                                   | Enables Foxy processing.                                                           |
| `manager`                 | string or `null` | `null`                                   | Selects `bun`, `deno`, `native`, `npm`, `pnpm`, or `yarn`; `null` means automatic. |
| `manager-version`         | string or map    | Empty                                    | Adds a constraint within the built-in supported manager range.                     |
| `manager-bin`             | string or map    | Manager executable                       | Overrides the manager executable.                                                  |
| `manager-options`         | string or map    | Empty                                    | Appends options to both install and update commands.                               |
| `manager-install-options` | string or map    | Empty                                    | Appends options only to install commands.                                          |
| `manager-update-options`  | string or map    | Empty                                    | Appends options only to update commands.                                           |
| `manager-timeout`         | integer or map   | No practical limit                       | Sets the manager process timeout in seconds.                                       |
| `run-asset-manager`       | boolean          | `true`                                   | Controls automatic manager probing and install or update execution.                |
| `fallback-asset`          | boolean          | `true`                                   | Restores `package.json` after asset processing fails.                              |
| `fallback-composer`       | boolean          | `true`                                   | Restores Composer lock and vendor state after asset solving fails.                 |
| `composer-asset-dir`      | string or `null` | `<vendor-dir>/php-forge/composer-asset/` | Sets the mock package directory.                                                   |
| `enable-packages`         | array or object  | `[]`                                     | Includes or excludes Composer packages by pattern.                                 |
| `root-package-json-dir`   | string or `null` | Project or package root                  | Sets the directory containing `package.json`.                                      |
| `registry-url`            | string           | `https://registry.npmjs.org`             | Sets the npm registry used by the `native` manager.                                |
| `native-install-dir`      | string           | `node_modules`                           | Sets the directory the `native` manager installs into.                             |

## Disabling Foxy

Set `enabled` to `false` to skip manager discovery, fallback snapshots, package merging, and manager execution:

```json
{
  "config": {
    "foxy": {
      "enabled": false
    }
  }
}
```

## Manager selection

Set the manager explicitly when local development and CI must always use the same tool:

```json
{
  "config": {
    "foxy": {
      "manager": "pnpm"
    }
  }
}
```

When `manager` is `null`, Foxy looks for one recognized lockfile (`package-lock.json`, `pnpm-lock.yaml`, `yarn.lock`,
`bun.lock`, `deno.lock`, or `foxy.lock`). Multiple recognized lockfiles require explicit selection. Without a lockfile,
available executables are considered in this order: npm, pnpm, Yarn, Bun, and Deno. The `native` manager is never
selected by availability; it is used only when `manager` is `native` or when `foxy.lock` is the single recognized
lockfile. Commit the lockfile generated by the selected manager. Foxy reports an error when an explicitly configured
manager is unknown, or when execution is enabled and its executable is unavailable.

When `run-asset-manager` is `false`, automatic selection does not probe executables. Foxy uses the manager identified
by a single recognized lockfile, or npm as the manifest adapter when no lockfile exists. Multiple lockfiles still
require an explicit `manager` value.

## Manager version constraints

When manager execution is enabled, Foxy validates the selected manager against its built-in supported constraint:

| Manager | Built-in constraint |
| ------- | ------------------- |
| Bun     | `^1.4.0`            |
| Deno    | `^2.9.7`            |
| npm     | `>=10.9.8`          |
| pnpm    | `^11.23.0`          |
| Yarn    | `^4.18.0`           |

The `native` manager has no binary and no version constraint; `manager-version`, `manager-bin`, `manager-options`,
`manager-install-options`, `manager-update-options`, and `manager-timeout` do not apply to it.

The `manager-version` option adds another Composer constraint that is evaluated together with the built-in constraint.
It can narrow the accepted versions for a project, but it cannot replace or widen Foxy's supported range.
Foxy treats the reported value as one concrete release and validates it from `root-package-json-dir` before every
manager command. During automatic Composer processing, `run-asset-manager=false` prevents probing, execution, and
validation of the manager binary. An explicit `composer foxy:audit` remains an exception because it is a direct user
request.

Narrow the npm constraint for one project:

```json
{
  "config": {
    "foxy": {
      "manager": "npm",
      "manager-version": "~10.9.8"
    }
  }
}
```

Manager-prefixed options may also use a map when a shared configuration supports several managers:

```json
{
  "config": {
    "foxy": {
      "manager-version": {
        "npm": "~10.9.8",
        "pnpm": "~11.23.0"
      }
    }
  }
}
```

For example, configuring npm with `<10.9.8` does not enable an older release because the built-in `>=10.9.8`
constraint remains in force. Remove `manager-version` to accept the complete built-in range for the selected manager.

## Manager executable and options

Use `manager-bin` for an explicit executable path:

```json
{
  "config": {
    "foxy": {
      "manager": "npm",
      "manager-bin": "/opt/node/bin/npm"
    }
  }
}
```

Use the three manager option settings only when native manager configuration files cannot express the requirement:

```json
{
  "config": {
    "foxy": {
      "manager": "npm",
      "manager-options": "--no-audit",
      "manager-install-options": "--ignore-scripts",
      "manager-update-options": "--save"
    }
  }
}
```

These values are appended to an external command. Treat project, global, and environment configuration as trusted
input. Prefer native files such as `.npmrc`, `.yarnrc.yml`, `pnpm-workspace.yaml`, or `bunfig.toml` where appropriate.
They apply to install and update operations only. `foxy:audit` owns its machine-output, advisory-filter, and
dependency-scope flags and does not inherit these options. It does honor `manager-bin`, `manager-version`,
`manager-timeout`, and `root-package-json-dir`.

Where the native manager supports an explicit override, Foxy neutralizes inherited settings that could exclude
dependencies or advisories. Bun 1.4 cannot reset every inherited dependency-scope setting without also discarding
registry configuration. Foxy therefore rejects a Bun audit when a loaded `.npmrc` or `bunfig.toml` excludes a dependency
type required by the requested audit. Remove the restrictive setting, or use `--no-dev` when the only restriction is the
development dependency graph. Audit preflight also requires UTF-8 configuration and canonical `[install]` table syntax;
inline or array install tables, escaped keys or omit values, and multiline values inside `[install]` are rejected rather
than interpreted heuristically. The preflight rejects restrictive declarations even when a later `include` or
higher-precedence file would override them.

## Deno

Deno installs a local `file:` dependency without installing that package's own dependencies. Foxy therefore also
registers each Composer asset directory as a member of the `workspaces` list in the root `package.json`:

```json
{
  "dependencies": {
    "@composer-asset/acme--theme": "file:./vendor/php-forge/composer-asset/acme/theme"
  },
  "workspaces": ["packages/*", "vendor/php-forge/composer-asset/acme/theme"]
}
```

- Existing `workspaces` entries are preserved and kept before the Foxy-managed members. Foxy removes a member when its
  Composer asset is removed, and removes the `workspaces` field when no entry remains.
- `workspaces` must be a list of strings. The object form with a `packages` key is rejected.
- Composer assets must be located in a subdirectory of the root package directory because Deno rejects workspace
  members outside it. Foxy writes each member as a normalized path relative to that directory. A
  `root-package-json-dir` that excludes the Composer vendor directory, or a `composer-asset-dir` outside the root
  package directory, is not supported with Deno.
- Updates run `deno update --lockfile-only --recursive && deno install`. The first step updates `deno.lock` within the
  declared version ranges, and the second installs the locked dependencies. `manager-options` applies to both steps,
  `manager-update-options` to the update step, and `manager-install-options` to the install step.
- Deno skips npm lifecycle scripts by default. Allow them with `--allow-scripts` in `manager-install-options`.
- `composer foxy:audit` reads `deno.lock` and parses the text report of `deno audit`. `--no-dev` is rejected because
  Deno cannot exclude development dependencies. See [Security auditing](usage.md#security-auditing).
- When switching from Deno to another manager, remove the Foxy-managed entries from `workspaces`.

```json
{
  "config": {
    "foxy": {
      "manager": "deno",
      "manager-install-options": "--allow-scripts"
    }
  }
}
```

## Native manager

Set `manager` to `native` to install the frontend dependencies without Bun, Deno, npm, pnpm, or Yarn:

```json
{
  "config": {
    "foxy": {
      "manager": "native"
    }
  }
}
```

Foxy then performs the installation itself, in PHP:

- It reads `dependencies` and `devDependencies` from the merged root `package.json`. Each `file:` entry (the
  Composer assets and any other local package) is copied into `node_modules/<name>`, and its own `dependencies`,
  non-optional `peerDependencies`, and `optionalDependencies` join the resolution.
- It resolves every other dependency against the registry with asset-packagist semantics: a flat `node_modules`
  with one version per package. Constraints from the root manifest, the local packages, and the transitive
  `dependencies`, `peerDependencies`, and `optionalDependencies` are intersected per package name, and the highest
  satisfying version wins. Dist-tags such as `latest` or `next` are honored. A conflict that only nested
  `node_modules` could solve is reported as an error that names every constraint and its origin; install such a
  project with a JavaScript manager instead. Unsatisfiable or missing optional dependencies are skipped with a
  warning, and a deprecated selection is reported with the registry's deprecation text.
- It downloads each tarball from the URL published by the registry, verifies it against the registry's
  `integrity` value (SHA-512, with the legacy SHA-1 `shasum` as a fallback), and extracts it into
  `node_modules/<name>`, stripping the archive's top-level directory like npm does. Tarballs are cached in
  `<cache-files-dir>/foxy/` and are subject to Composer's `cache-files-ttl`, `cache-files-maxsize`, and
  `cache-read-only` settings; every cached tarball is verified again before use.
- It writes `foxy.lock`, which records the root requirements, the requirements of every local package, and the
  selected version, tarball URL, and integrity of every installed package. `composer install` with a lock that
  matches the current manifests reinstalls exactly those tarballs without a single metadata request;
  `composer update`, or a lock that no longer matches, resolves again and rewrites the lock. Commit `foxy.lock`.
- It removes entries of the install directory that are not part of the installation (dot-entries such as `.bin`
  are left alone).

The install directory is `node_modules` next to `package.json` by default. `native-install-dir` changes it; a
relative value is resolved from `root-package-json-dir`, an absolute value is used as is. A Yii 2 application that
keeps the framework's default `@npm` alias can install straight into `vendor/npm-asset`:

```json
{
  "config": {
    "foxy": {
      "manager": "native",
      "native-install-dir": "vendor/npm-asset"
    }
  }
}
```

Foxy owns that directory: every entry that the lock does not list is removed on every install, except entries whose
name starts with a dot (such as `.bin`), so do not point it at a directory that also receives asset-packagist
packages from Composer. The root package directory, any of its parents, and filesystem roots are rejected. Package
names read from `package.json`, `foxy.lock`, and the registry must follow npm's name grammar (an optional `@scope/`
followed by a name of letters, digits, `.`, `_`, and `-`, up to 214 characters); any other name is rejected before a
path is built from it. Scoped packages keep the npm layout (`vendor/npm-asset/@popperjs/core`), not asset-packagist's
`popperjs--core` form.

The native manager requires the PHP `zlib` extension and prints `Installing`, `Updating`, and `Removing` lines like
the other managers. Its registry requests go through Composer's HTTP layer: proxies, `cafile`, `disable-tls`, and the
credentials stored in `auth.json` for the registry host all apply, so a private registry can be configured with
`registry-url` plus Composer authentication:

```json
{
  "config": {
    "foxy": {
      "manager": "native",
      "registry-url": "https://npm.example.com"
    }
  }
}
```

The registry is expected to answer the npm metadata API (`GET /<name>` with
`Accept: application/vnd.npm.install-v1+json`) and to serve the tarball URLs it publishes. HTTP 429 responses are
retried up to three times, honoring `Retry-After` up to 60 seconds; other failures are reported immediately.

The native manager implements the subset that frontend assets need. It does not support:

- `npm:` aliases, `git`, `github:`, `http(s)`, `workspace:`, and `link:` specifications; each is rejected with an
  error that names the dependency.
- Nested `node_modules`: two packages that require incompatible versions of the same dependency cannot be installed
  together.
- Lifecycle scripts (`preinstall`, `install`, `postinstall`, `prepare`), `bin` links, `os`, `cpu`, and `engines`
  filters, `overrides`, and `resolutions`. Scripts are never executed; packages that need a build step at install
  time must be installed with a JavaScript manager.
- Prerelease identifiers that Composer's version parser does not understand, such as `5.0.0-next.3` or
  `1.0.0-canary.1`: those versions are skipped, and a range or dist-tag that selects one is rejected. Prereleases
  named `alpha`, `beta`, `RC`, `dev`, and `patch` are matched with npm's rule: only a range that names a prerelease
  of the same `major.minor.patch` can select it.
- Symbolic and hard links inside tarballs (skipped) and `..` or absolute entry paths (rejected).
- `composer foxy:audit`.

Selection by availability is also disabled for this manager: without `manager: native` or a `foxy.lock` file, a
project without a JavaScript manager still reports `No asset manager was found.`.

## Manager timeout

Set a timeout in seconds for the frontend manager process:

```json
{
  "config": {
    "foxy": {
      "manager-timeout": 420
    }
  }
}
```

## Package merging without manager execution

Disable manager binary probing and execution while continuing to update `package.json`:

```json
{
  "config": {
    "foxy": {
      "run-asset-manager": false
    }
  }
}
```

In this manifest-only mode, Foxy does not probe manager binaries, run install or update commands, or remove existing
`node_modules/@composer-asset/*` installations during npm reconciliation. Generated Composer asset manifests and the
root `package.json` are still updated. An explicit `composer foxy:audit` remains available and validates and runs the
selected manager because it represents a direct user request.

## Fallbacks

Both fallbacks are enabled by default. They can be controlled independently:

```json
{
  "config": {
    "foxy": {
      "fallback-asset": true,
      "fallback-composer": false
    }
  }
}
```

- `fallback-asset` restores the project `package.json` when package merging or manager execution fails.
- `fallback-composer` restores the captured lock data and installed Composer dependencies for any exception or non-zero
  manager result during asset solving.

Foxy rethrows the original solve error when Composer restoration succeeds. If restoration also fails, Foxy reports the
rollback failure and retains the original error as the previous exception. Disabled fallbacks do not create snapshots.

Composer lock and vendor state are captured at Composer's `pre-operations-exec` event. This is not a fully atomic
transaction for `composer require` or `composer remove`: Composer may update the root `composer.json` before that event,
and Foxy does not retain its previous bytes. Inspect and, when necessary, revert `composer.json` after a failed command.

## Mock package directory

By default, Foxy writes local mock packages under `<vendor-dir>/php-forge/composer-asset/`. Override the location with:

```json
{
  "config": {
    "foxy": {
      "composer-asset-dir": "runtime/foxy-assets"
    }
  }
}
```

Foxy recursively resets this directory during each solve. To prevent deletion outside Foxy-owned storage, it rejects:

- An empty path, filesystem root, project root, vendor root, or a parent directory that contains either protected root.
- A symbolic link used as the asset directory.
- An existing, non-empty custom directory without Foxy's `.foxy-managed` ownership marker.

A new or empty custom directory is accepted and receives the marker automatically. Before upgrading an existing custom
directory, verify that it contains only generated Foxy data, remove its contents, and let Foxy recreate and mark it.

## Package selection

Foxy normally processes installed packages that require Foxy or declare `extra.foxy=true`. The root project can include
or exclude package names with glob patterns or regular expressions:

```json
{
  "config": {
    "foxy": {
      "enable-packages": {
        "foo/*": true,
        "foo/legacy-*": false,
        "/^acme\\/theme-/": true
      }
    }
  }
}
```

When no package must be excluded, a list is sufficient:

```json
{
  "config": {
    "foxy": {
      "enable-packages": ["foo/*", "/^acme\\/theme-/"]
    }
  }
}
```

Patterns are evaluated in declaration order; the first matching pattern determines the result.

## package.json directory

In the root application, `root-package-json-dir` controls the `package.json` read/write path and manager working
directory:

```json
{
  "config": {
    "foxy": {
      "root-package-json-dir": "web"
    }
  }
}
```

Relative root-project paths are resolved from the Composer project directory. Absolute paths and filesystem roots are
supported.

In an installed Composer library, the same option is resolved relative to that library's installation directory and
identifies the directory containing its embedded `package.json`. The resolved manifest must remain inside the
library's Composer installation directory.

## Next steps

- 📚 [Getting started](index.md)
- 💡 [Usage guide](usage.md)
- 🔌 [Events reference](events.md)
- ❓ [Frequently asked questions](faqs.md)
- 🧪 [Testing guide](testing.md)
- ⬆️ [Upgrade guide](../UPGRADE.md)
- 📖 [README](../README.md)
