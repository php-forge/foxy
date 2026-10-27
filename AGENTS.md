# Foxy repository guide

Foxy is the `php-forge/foxy` Composer plugin. Inspect `composer.json`, the current Git diff, relevant source and tests
before changing behavior. Preserve existing staged and unstaged work. Public documentation is English; use `docs/` for
usage and configuration and `skills/php-forge-foxy/` as the canonical agent skill. Client discovery paths link to that
source.

The plugin merges eligible Composer assets and uses the selected frontend manager or its own native manager. Preserve
user-owned dependencies, manager and lockfile semantics, managed-directory safeguards, audit failure reporting and
fallback behavior. The root `composer.json` is not restored by Foxy's fallback. Check `docs/config.md` and
`docs/usage.md` for the precise contracts before suggesting changes.

Use the applicable checks in `docs/testing.md`; commands such as `composer ecs` and `composer rector` modify files. Run
only appropriate non-mutating checks and report missing dependencies or skipped scenarios. Keep changes local and do not
stage, commit or publish unless explicitly requested.
