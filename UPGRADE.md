# Upgrade Guide

The [migration guide](docs/migration.md) explains the actions and configuration examples for each release. These entries
list released incompatibilities.

<a id="upgrading-foxy"></a> <a id="upgrading-from-03x-to-04x"></a>

## 0.4.0

<a id="update-the-constraint"></a>

- Update `php-forge/foxy` to `^0.4` with `--with-all-dependencies`; PHP 8.3 projects must use a compatible Symfony
  Console 7.4 release.

<a id="the-manager-is-selected-on-first-use"></a>

- Manager validation now happens when an install, update or explicit audit needs it. CI that depended on an earlier
  failure must run `composer install` or `composer foxy:audit`.

<a id="native-manager"></a>

- Native audits require the registry bulk advisories endpoint when `foxy.lock` contains registry packages. A lock with
  only local `file:` packages skips the registry request. Native audits still reject `--no-dev`, so audit the complete
  graph. The first native install writes `.foxy-installed.json` and extracts registry packages once.

<a id="rollbacks"></a>

- Asset restoration now precedes `foxy.post-solve`; listeners that throw there no longer trigger that restoration.
  Composer rollback now dispatches package events: guard root package-event scripts that must not run during rollback.

<a id="php-api"></a>

- Custom `AbstractAssetManager` subclasses must remove the former default-binary argument to `buildCommand()` and
  `buildUnconfiguredCommand()` and override `getDefaultBinary()` when needed.

<a id="upgrading-from-02x-to-03x"></a>

## 0.3.0

<a id="runtime-requirements"></a>

- Upgrade to PHP 8.3 or later and Composer 2.10.2 or later, and install a supported manager version when execution or
  audit requires one; see the [requirements](docs/index.md#requirements).

<a id="update-the-constraint-and-authorize-the-plugin"></a>

- Update the Foxy constraint to `^0.3` and explicitly allow the Composer plugin.

<a id="choose-the-manager"></a>

- Select a manager explicitly when several recognized lockfiles exist, remove obsolete lockfiles and upgrade older
  manager versions as described in the [migration guide](docs/migration.md#choose-the-manager).

<a id="embedded-package-metadata-and-paths"></a>

- Move runtime frontend requirements into `dependencies`, `optionalDependencies` or `peerDependencies`; embedded
  lifecycle scripts and other publishing metadata are no longer propagated. Keep embedded manifests within the library
  installation directory and verify custom generated directories contain only Foxy-managed data.

<a id="fallbacks-and-disabled-modes"></a>

- Review fallback settings and the root `composer.json` after failed `require` or `remove` operations: Composer rollback
  does not restore that file. Disabled manager execution still allows explicit audits.
