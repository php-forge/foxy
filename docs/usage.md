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
