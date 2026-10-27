---
name: php-forge-foxy
description:
  Use when configuring php-forge/foxy in a Composer application or library,
  selecting an asset manager, troubleshooting package merges, or auditing
  frontend dependencies.
license: BSD-3-Clause
---

# Configure Foxy

Foxy is a Composer plugin that merges eligible Composer libraries' frontend manifests into an application and invokes a
selected frontend manager, or installs assets itself with the native manager. Match the installed revision before
applying these instructions.

## Establish the revision and scope

For a root checkout, inspect `composer show --self --format=json`, its HEAD and working tree. For an installed
dependency, resolve the configured vendor directory and inspect `composer/installed.php` for `php-forge/foxy`
(`install_path`, `pretty_version`, and selected `reference`), then check `composer.lock`. A symlinked checkout can
differ from both metadata and lock: inspect its HEAD and current files. Uncommitted changes have no matching revision.
Use documentation from the matching revision; if unavailable, inspect installed source and report the gap.

Read only the relevant canonical local guide: `docs/index.md` for requirements and installation, `docs/config.md` for
configuration, `docs/usage.md` for workflows and examples, `docs/events.md` for extension events, or `docs/faqs.md` for
troubleshooting. For an upgrade, use `UPGRADE.md` for the incompatibility summary and `docs/migration.md` for detailed
actions. Guides can be absent from Composer distributions; use the matching repository revision. Check `composer.json`,
the root and library manifests and their lockfiles before proposing commands. Preserve existing project dependencies and
manager choice.

## Choose a setup

- The application must require `php-forge/foxy` and explicitly authorize it in Composer `allow-plugins`. A library's
  `require-dev` does not install Foxy for consumers. A library may declare `extra.foxy=true`; its consumer still needs
  the plugin.
- Select `bun`, `deno`, `npm`, `pnpm`, `yarn`, or `native` explicitly for reproducible CI. Without a selection, one
  recognized lockfile chooses the manager; multiple lockfiles cause an error. Native is selected only explicitly or by a
  single `foxy.lock`, never by executable discovery.
- `run-asset-manager=false` skips automatic binary probing and execution but still updates manifests; an explicit
  `composer foxy:audit` still executes the selected manager. Do not present this mode as an install or an audit.
- Native mode requires PHP zlib, owns its install directory and removes unlisted non-dot entries. Do not share that
  directory with another installer. It does not run lifecycle scripts, create executable links, support nested
  incompatible versions, or accept Git, URL, alias, workspace or link specifications. Use a JavaScript manager when
  those features are needed.
- `root-package-json-dir` sets the application manifest and manager working directory. In a library it is relative to
  the installed library and must remain inside it. Deno workspace members must remain inside the root package directory.

## Handle failures and audit

Manager version overrides narrow rather than widen built-in support. Inspect the selected revision's requirements and
actual binary before changing a constraint. `composer foxy:audit` needs the selected lockfile; exit 0 means no finding
reached the threshold, 1 means a finding did, and 2 means a reliable audit could not be produced. Deno and native reject
`--no-dev`. Do not treat status 2 as a clean audit or run an automatic fix as a substitute.

Foxy can restore the asset manifest and Composer lock/vendor state after a solve failure, but the root `composer.json`
bytes are not part of the rollback. Inspect it after failed `require` or `remove`. Never point generated asset storage
at user-owned data; Foxy resets its managed directory and validates ownership.

## Verify behavior

Test in a disposable project or fixture before touching real lockfiles. Verify the effective manager, merged manifest
and preserved unrelated dependencies, then repeat the same operation to check idempotence. Exercise the applicable error
path (multiple lockfiles, missing binary, invalid options or unsupported native specification) and confirm the error is
surfaced and expected state is preserved. For audit changes, test statuses 0, 1 and 2 independently. Run the consuming
project's checks and report whether actual manager execution, native skill discovery and activation were verified;
reading this file alone proves neither activation nor a successful installation.
