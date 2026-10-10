<?php

declare(strict_types=1);

namespace Foxy\Exception;

use function sprintf;

/**
 * Represents error message templates for Foxy exceptions.
 *
 * Use {@see Message::getMessage()} to format the template with `sprintf()` arguments.
 */
enum Message: string
{
    /**
     * Error when a Bun audit configuration file (`bunfig.toml` or `.npmrc`) cannot be read.
     *
     * Format: "The Bun audit configuration "%s" cannot be read."
     */
    case ASSET_BUN_AUDIT_CONFIG_UNREADABLE = 'The Bun audit configuration "%s" cannot be read.';

    /**
     * Reason fragment when npmrc keys contain escape sequences.
     *
     * Format: "escape sequences in npmrc keys are not supported; use canonical keys"
     */
    case ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES = 'escape sequences in npmrc keys are not supported; use canonical '
        . 'keys';

    /**
     * Reason fragment when npmrc omit values contain escape sequences.
     *
     * Format: "escape sequences in npmrc omit values are not supported; use canonical values"
     */
    case ASSET_BUN_AUDIT_REASON_NPMRC_OMIT_ESCAPES = 'escape sequences in npmrc omit values are not supported; use '
        . 'canonical values';

    /**
     * Reason fragment when bunfig declares an array install table.
     *
     * Format: "array install tables are not supported; use an [install] table"
     */
    case ASSET_BUN_AUDIT_REASON_TOML_ARRAY_INSTALL_TABLE = 'array install tables are not supported; use an [install] '
        . 'table';

    /**
     * Reason fragment when bunfig declares an inline install table.
     *
     * Format: "inline install tables are not supported; use an [install] table"
     */
    case ASSET_BUN_AUDIT_REASON_TOML_INLINE_INSTALL_TABLE = 'inline install tables are not supported; use an '
        . '[install] table';

    /**
     * Reason fragment when bunfig keys contain escape sequences.
     *
     * Format: "escape sequences in TOML keys are not supported; use canonical keys"
     */
    case ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES = 'escape sequences in TOML keys are not supported; use canonical '
        . 'keys';

    /**
     * Reason fragment when an [install] value is a multiline array or inline table.
     *
     * Format: "multiline container values in [install] are not supported"
     */
    case ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER = 'multiline container values in [install] are not supported';

    /**
     * Reason fragment when an [install] value is a multiline string.
     *
     * Format: "multiline strings in [install] are not supported"
     */
    case ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_STRING = 'multiline strings in [install] are not supported';

    /**
     * Reason fragment when an audit configuration is not valid UTF-8.
     *
     * Format: "the configuration must be UTF-8"
     */
    case ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED = 'the configuration must be UTF-8';

    /**
     * Error when a Bun configuration file (`bunfig.toml` or `.npmrc`) restricts the audited dependency scope.
     *
     * Format: "The Bun audit cannot guarantee the requested dependency scope because "%s" declares "%s"."
     */
    case ASSET_BUN_AUDIT_SCOPE_RESTRICTED = 'The Bun audit cannot guarantee the requested dependency scope because '
        . '"%s" declares "%s".';

    /**
     * Error when the dependency scope of a Bun configuration file (`bunfig.toml` or `.npmrc`) cannot be verified.
     *
     * Format: "The Bun audit cannot verify dependency scope in "%s": %s."
     */
    case ASSET_BUN_AUDIT_SCOPE_UNVERIFIABLE = 'The Bun audit cannot verify dependency scope in "%s": %s.';

    /**
     * Error when a production-only Deno audit is requested.
     *
     * Format: "The Deno audit cannot guarantee the requested dependency scope because "deno audit" cannot exclude
     *         development dependencies."
     */
    case ASSET_DENO_AUDIT_NO_DEV_UNSUPPORTED = 'The Deno audit cannot guarantee the requested dependency scope '
        . 'because "deno audit" cannot exclude development dependencies.';

    /**
     * Error when a Composer asset is not nested in the root package directory.
     *
     * Format: "The Composer asset "%s" must be located in a subdirectory of the root package directory to be installed
     *         with Deno."
     */
    case ASSET_DENO_COMPOSER_ASSET_NOT_NESTED = 'The Composer asset "%s" must be located in a subdirectory of the '
        . 'root package directory to be installed with Deno.';

    /**
     * Error when the Deno "workspaces" field is not a list of strings.
     *
     * Format: "The "workspaces" field of "%s" must be a list of strings to install Composer assets with Deno."
     */
    case ASSET_DENO_WORKSPACES_INVALID = 'The "workspaces" field of "%s" must be a list of strings to install '
        . 'Composer assets with Deno.';

    /**
     * Error when the asset manager binary is not installed.
     *
     * Format: "The "%s" binary must be installed."
     */
    case ASSET_MANAGER_BINARY_NOT_INSTALLED = 'The "%s" binary must be installed.';

    /**
     * Error when the asset manager exits with a non-zero status code.
     *
     * Format: "The asset manager exited with status code %d."
     */
    case ASSET_MANAGER_EXITED_WITH_STATUS = 'The asset manager exited with status code %d.';

    /**
     * Error when restoring the fallback after an asset manager failure also fails.
     *
     * Format: "The asset manager failed and its fallback could not be restored: %s"
     */
    case ASSET_MANAGER_FALLBACK_RESTORE_FAILED = 'The asset manager failed and its fallback could not be restored: %s';

    /**
     * Error when an audit is requested without the manager lock file.
     *
     * Format: "The %s lock file "%s" was not found."
     */
    case ASSET_MANAGER_LOCK_FILE_MISSING = 'The %s lock file "%s" was not found.';

    /**
     * Error when several asset manager lock files exist.
     *
     * Format: "Multiple asset manager lock files were found; configure "manager" explicitly."
     */
    case ASSET_MANAGER_LOCK_FILES_AMBIGUOUS = 'Multiple asset manager lock files were found; configure "manager" '
        . 'explicitly.';

    /**
     * Error when the asset manager selected by its lock file is unavailable.
     *
     * Format: "The asset manager "%s" selected by its lock file is not available."
     */
    case ASSET_MANAGER_LOCKED_UNAVAILABLE = 'The asset manager "%s" selected by its lock file is not available.';

    /**
     * Error when no asset manager is found.
     *
     * Format: "No asset manager was found."
     */
    case ASSET_MANAGER_NONE_FOUND = 'No asset manager was found.';

    /**
     * Error when the configured asset manager does not exist.
     *
     * Format: "The asset manager "%s" doesn't exist."
     */
    case ASSET_MANAGER_UNKNOWN = 'The asset manager "%s" doesn\'t exist.';

    /**
     * Error when the npm workspace graph cannot be enumerated.
     *
     * Format: "The npm workspace graph could not be enumerated from package-lock.json. Regenerate the lock file with a
     *         supported npm version."
     */
    case ASSET_NPM_WORKSPACE_GRAPH_UNENUMERABLE = 'The npm workspace graph could not be enumerated from '
        . 'package-lock.json. Regenerate the lock file with a supported npm version.';

    /**
     * Error when the root package directory does not exist.
     *
     * Format: "The root package directory "%s" doesn't exist."
     */
    case ASSET_ROOT_PACKAGE_DIR_MISSING = 'The root package directory "%s" doesn\'t exist.';

    /**
     * Error when the installed version does not satisfy the configured "manager-version".
     *
     * Format: "The installed %s version "%s" doesn't match the configured version constraint "%s"."
     */
    case ASSET_VERSION_CONSTRAINT_MISMATCH = 'The installed %s version "%s" doesn\'t match the configured version '
        . 'constraint "%s".';

    /**
     * Error when the installed version does not satisfy the supported constraint.
     *
     * Format: "The installed %s version "%s" doesn't match the supported version constraint "%s"."
     */
    case ASSET_VERSION_UNSUPPORTED = 'The installed %s version "%s" doesn\'t match the supported version '
        . 'constraint "%s".';

    /**
     * Bun parser detail when a package lacks an advisory list.
     *
     * Format: "each package must contain a list of advisories"
     */
    case AUDIT_BUN_PACKAGE_ADVISORIES_REQUIRED = 'each package must contain a list of advisories';

    /**
     * Error when Bun reports diagnostics that may indicate a partial report.
     *
     * Format: "The Bun audit command produced diagnostics and may have returned a partial report. %s"
     */
    case AUDIT_BUN_PARTIAL_REPORT = 'The Bun audit command produced diagnostics and may have returned a partial '
        . 'report. %s';

    /**
     * Error when the audit command fails without diagnostics.
     *
     * Format: "The %s audit command failed with status code %d."
     */
    case AUDIT_COMMAND_FAILED = 'The %s audit command failed with status code %d.';

    /**
     * Error when the audit command fails and emits diagnostics.
     *
     * Format: "The %s audit command failed with status code %d. %s"
     */
    case AUDIT_COMMAND_FAILED_WITH_DIAGNOSTICS = 'The %s audit command failed with status code %d. %s';

    /**
     * Deno parser detail when advisory actions do not match the patched versions.
     *
     * Format: "%s actions must update the package to its patched versions"
     */
    case AUDIT_DENO_ADVISORY_ACTIONS_MISMATCH = '%s actions must update the package to its patched versions';

    /**
     * Deno parser detail when an advisory block has an unexpected line count.
     *
     * Format: "%s must contain %d or %d lines"
     */
    case AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID = '%s must contain %d or %d lines';

    /**
     * Deno parser detail when an advisory line is not recognized.
     *
     * Format: "%s line %d is not recognized"
     */
    case AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED = '%s line %d is not recognized';

    /**
     * Deno parser detail when the report is empty.
     *
     * Format: "the report is empty"
     */
    case AUDIT_DENO_REPORT_EMPTY = 'the report is empty';

    /**
     * Deno parser detail when the report contains no advisory.
     *
     * Format: "the report does not contain any advisory"
     */
    case AUDIT_DENO_REPORT_NO_ADVISORY = 'the report does not contain any advisory';

    /**
     * Deno parser detail when a severity count differs from the summary.
     *
     * Format: "the summary reports %d %s vulnerabilities but the report contains %d"
     */
    case AUDIT_DENO_SUMMARY_SEVERITY_MISMATCH = 'the summary reports %d %s vulnerabilities but the report contains %d';

    /**
     * Deno parser detail when the advisory count differs from the summary.
     *
     * Format: "the summary reports %d vulnerabilities but the report contains %d advisories"
     */
    case AUDIT_DENO_SUMMARY_TOTAL_MISMATCH = 'the summary reports %d vulnerabilities but the report contains %d '
        . 'advisories';

    /**
     * Deno parser detail when the summary line is missing or unrecognized.
     *
     * Format: "the report does not end with a recognized summary"
     */
    case AUDIT_DENO_SUMMARY_UNRECOGNIZED = 'the report does not end with a recognized summary';

    /**
     * Parser detail when a field is not a boolean.
     *
     * Format: "%s.%s must be a boolean"
     */
    case AUDIT_FIELD_BOOLEAN_REQUIRED = '%s.%s must be a boolean';

    /**
     * Parser detail when a field is not a non-negative integer.
     *
     * Format: "%s.%s must be a non-negative integer"
     */
    case AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED = '%s.%s must be a non-negative integer';

    /**
     * Parser detail when a field is not a string.
     *
     * Format: "%s.%s must be a string"
     */
    case AUDIT_FIELD_STRING_REQUIRED = '%s.%s must be a string';

    /**
     * Error when an advisory identifier is not a valid GHSA identifier.
     *
     * Format: "The advisory identifier "%s" is not a valid GHSA identifier."
     */
    case AUDIT_GHSA_ID_INVALID = 'The advisory identifier "%s" is not a valid GHSA identifier.';

    /**
     * Error when the GitHub advisory response is not a JSON object.
     *
     * Format: "GitHub returned an invalid advisory document for %s."
     */
    case AUDIT_GITHUB_ADVISORY_INVALID = 'GitHub returned an invalid advisory document for %s.';

    /**
     * Error when the GitHub advisory response belongs to another advisory.
     *
     * Format: "GitHub returned a mismatched advisory document for %s."
     */
    case AUDIT_GITHUB_ADVISORY_MISMATCHED = 'GitHub returned a mismatched advisory document for %s.';

    /**
     * Error when the GitHub advisory identifiers are not a list.
     *
     * Format: "GitHub returned invalid identifiers for %s."
     */
    case AUDIT_GITHUB_IDENTIFIERS_INVALID = 'GitHub returned invalid identifiers for %s.';

    /**
     * Appends the manager diagnostics to a parser failure message.
     *
     * Format: "%s Manager error: %s"
     */
    case AUDIT_MANAGER_ERROR_APPENDED = '%s Manager error: %s';

    /**
     * npm parser detail when "fixAvailable" has an invalid type.
     *
     * Format: "%s.fixAvailable must be a boolean or object"
     */
    case AUDIT_NPM_FIX_AVAILABLE_INVALID = '%s.fixAvailable must be a boolean or object';

    /**
     * npm parser detail when metadata counts differ from the entries.
     *
     * Format: "metadata vulnerability counts must equal the vulnerability entries"
     */
    case AUDIT_NPM_METADATA_COUNT_MISMATCH = 'metadata vulnerability counts must equal the vulnerability entries';

    /**
     * npm parser detail when a vulnerability name differs from its key.
     *
     * Format: "%s.name must match its vulnerability key"
     */
    case AUDIT_NPM_NAME_KEY_MISMATCH = '%s.name must match its vulnerability key';

    /**
     * npm parser detail when the report version is not 2.
     *
     * Format: "auditReportVersion must be 2"
     */
    case AUDIT_NPM_REPORT_VERSION_UNSUPPORTED = 'auditReportVersion must be 2';

    /**
     * npm parser detail when a "via" entry has an invalid type.
     *
     * Format: "%s.via.%d must be a string or object"
     */
    case AUDIT_NPM_VIA_ENTRY_INVALID = '%s.via.%d must be a string or object';

    /**
     * npm parser detail when "via" is empty or not a list.
     *
     * Format: "%s.via must be a non-empty list"
     */
    case AUDIT_NPM_VIA_LIST_EMPTY = '%s.via must be a non-empty list';

    /**
     * npm parser detail when a vulnerability has an empty key.
     *
     * Format: "each vulnerability must be keyed by a package name"
     */
    case AUDIT_NPM_VULNERABILITY_KEY_REQUIRED = 'each vulnerability must be keyed by a package name';

    /**
     * Error wrapper for malformed audit output; the second argument is a parser detail case.
     *
     * Format: "The %s audit output is malformed: %s."
     */
    case AUDIT_OUTPUT_MALFORMED = 'The %s audit output is malformed: %s.';

    /**
     * Error when no audit parser exists for the asset manager.
     *
     * Format: "The asset manager "%s" does not provide a supported audit report."
     */
    case AUDIT_PARSER_UNSUPPORTED_MANAGER = 'The asset manager "%s" does not provide a supported audit report.';

    /**
     * pnpm parser detail when an advisory has no findings.
     *
     * Format: "%s.findings must be a non-empty list"
     */
    case AUDIT_PNPM_FINDINGS_EMPTY = '%s.findings must be a non-empty list';

    /**
     * pnpm parser detail when an advisory id is invalid.
     *
     * Format: "%s.id must be a non-negative integer"
     */
    case AUDIT_PNPM_ID_INVALID = '%s.id must be a non-negative integer';

    /**
     * pnpm parser detail when an advisory id differs from its key.
     *
     * Format: "%s.id must match its advisory key"
     */
    case AUDIT_PNPM_ID_KEY_MISMATCH = '%s.id must match its advisory key';

    /**
     * pnpm parser detail when metadata counts differ from the entries.
     *
     * Format: "metadata vulnerability counts must equal the advisory entries"
     */
    case AUDIT_PNPM_METADATA_COUNT_MISMATCH = 'metadata vulnerability counts must equal the advisory entries';

    /**
     * Parser detail when the manager returned an error document.
     *
     * Format: "the manager returned an error document"
     */
    case AUDIT_REPORT_ERROR_DOCUMENT = 'the manager returned an error document';

    /**
     * Parser detail when the report is not valid JSON.
     *
     * Format: "invalid JSON"
     */
    case AUDIT_REPORT_JSON_INVALID = 'invalid JSON';

    /**
     * Parser detail when the report is not a JSON object.
     *
     * Format: "expected a JSON object"
     */
    case AUDIT_REPORT_JSON_OBJECT_EXPECTED = 'expected a JSON object';

    /**
     * Parser detail when the report exceeds the size limit.
     *
     * Format: "the report exceeds the 16 MiB safety limit"
     */
    case AUDIT_REPORT_SIZE_LIMIT_EXCEEDED = 'the report exceeds the 16 MiB safety limit';

    /**
     * Parser detail when the severity total differs from the sum of counts.
     *
     * Format: "%s.vulnerabilities.total must equal the severity counts"
     */
    case AUDIT_SEVERITY_TOTAL_MISMATCH = '%s.vulnerabilities.total must equal the severity counts';

    /**
     * Error when a successful exit status accompanies reported vulnerabilities.
     *
     * Format: "The %s audit report contains vulnerabilities but the manager returned a successful status."
     */
    case AUDIT_SUCCESS_STATUS_WITH_FINDINGS = 'The %s audit report contains vulnerabilities but the manager returned '
        . 'a successful status.';

    /**
     * Parser detail when a value contains an invalid CVE identifier.
     *
     * Format: "%s contains an invalid CVE identifier"
     */
    case AUDIT_VALUE_CVE_INVALID = '%s contains an invalid CVE identifier';

    /**
     * Parser detail when a value is empty.
     *
     * Format: "%s must not be empty"
     */
    case AUDIT_VALUE_EMPTY = '%s must not be empty';

    /**
     * Parser detail when a value is not a list.
     *
     * Format: "%s must be a list"
     */
    case AUDIT_VALUE_LIST_REQUIRED = '%s must be a list';

    /**
     * Parser detail when a value is not an object.
     *
     * Format: "%s must be an object"
     */
    case AUDIT_VALUE_OBJECT_REQUIRED = '%s must be an object';

    /**
     * Parser detail when a severity is unsupported.
     *
     * Format: "%s has an unsupported severity"
     */
    case AUDIT_VALUE_SEVERITY_UNSUPPORTED = '%s has an unsupported severity';

    /**
     * Parser detail when a value is neither a string nor an integer.
     *
     * Format: "%s must be a string or integer"
     */
    case AUDIT_VALUE_STRING_OR_INTEGER_REQUIRED = '%s must be a string or integer';

    /**
     * Parser detail when a list contains non-string items.
     *
     * Format: "%s must contain only strings"
     */
    case AUDIT_VALUE_STRINGS_ONLY = '%s must contain only strings';

    /**
     * Yarn parser detail when a line is not valid JSON.
     *
     * Format: "line %d contains invalid JSON"
     */
    case AUDIT_YARN_LINE_JSON_INVALID = 'line %d contains invalid JSON';

    /**
     * Yarn parser detail when a line is not an audit finding.
     *
     * Format: "line %d is not an audit finding"
     */
    case AUDIT_YARN_LINE_NOT_FINDING = 'line %d is not an audit finding';

    /**
     * Error when Composer passes invalid command capability arguments.
     *
     * Format: "Composer provided invalid Foxy command capability arguments."
     */
    case COMMAND_CAPABILITY_ARGUMENTS_INVALID = 'Composer provided invalid Foxy command capability arguments.';

    /**
     * Error when an environment variable does not contain valid JSON.
     *
     * Format: "The "%s" environment variable isn't valid JSON."
     */
    case CONFIG_ENV_JSON_INVALID = 'The "%s" environment variable isn\'t valid JSON.';

    /**
     * Error when the current working directory cannot be determined.
     *
     * Format: "Unable to get the current working directory."
     */
    case CURRENT_WORKING_DIRECTORY_UNAVAILABLE = 'Unable to get the current working directory.';

    /**
     * Error when the fallback asset path is not a regular file.
     *
     * Format: "The fallback asset path "%s" must be a regular file."
     */
    case FALLBACK_ASSET_PATH_NOT_FILE = 'The fallback asset path "%s" must be a regular file.';

    /**
     * Error when the fallback asset file cannot be read.
     *
     * Format: "Unable to read fallback asset file "%s"."
     */
    case FALLBACK_ASSET_READ_FAILED = 'Unable to read fallback asset file "%s".';

    /**
     * Error when the fallback asset file cannot be removed.
     *
     * Format: "Unable to remove fallback asset file "%s"."
     */
    case FALLBACK_ASSET_REMOVE_FAILED = 'Unable to remove fallback asset file "%s".';

    /**
     * Error when the fallback asset file cannot be written.
     *
     * Format: "Unable to write fallback asset file "%s"."
     */
    case FALLBACK_ASSET_WRITE_FAILED = 'Unable to write fallback asset file "%s".';

    /**
     * Error when a Composer fallback path cannot be removed.
     *
     * Format: "Unable to remove Composer fallback path "%s"."
     */
    case FALLBACK_COMPOSER_REMOVE_FAILED = 'Unable to remove Composer fallback path "%s".';

    /**
     * Error when the Composer installer fails during restoration.
     *
     * Format: "Unable to restore Composer dependencies, installer exited with code %d."
     */
    case FALLBACK_COMPOSER_RESTORE_FAILED = 'Unable to restore Composer dependencies, installer exited with code %d.';

    /**
     * Error when an audit is requested while Foxy is disabled.
     *
     * Format: "Foxy is disabled; frontend dependencies cannot be audited."
     */
    case FOXY_AUDIT_DISABLED = 'Foxy is disabled; frontend dependencies cannot be audited.';

    /**
     * Error when the asset manager does not support audits.
     *
     * Format: "The selected asset manager does not support security audits."
     */
    case FOXY_AUDIT_UNSUPPORTED_MANAGER = 'The selected asset manager does not support security audits.';

    /**
     * Error when a JSON file cannot be read.
     *
     * Format: "Unable to read JSON file "%s"."
     */
    case JSON_FILE_UNREADABLE = 'Unable to read JSON file "%s".';

    /**
     * Error when a PHP extension required by the native manager is not loaded.
     *
     * Format: "The native manager requires the "%s" PHP extension."
     */
    case NATIVE_EXTENSION_MISSING = 'The native manager requires the "%s" PHP extension.';

    /**
     * Error when the native install directory would make the installer prune the project.
     *
     * Format: "The native install directory "%s" must not be the root package directory, one of its parents, or a
     * filesystem root."
     */
    case NATIVE_INSTALL_DIR_INVALID = 'The native install directory "%s" must not be the root package directory, one of '
        . 'its parents, or a filesystem root.';

    /**
     * Error when a downloaded tarball does not match its integrity hash.
     *
     * Format: "The tarball "%s" failed its integrity check."
     */
    case NATIVE_INTEGRITY_MISMATCH = 'The tarball "%s" failed its integrity check.';

    /**
     * Error when a `file:` dependency directory has no `package.json`.
     *
     * Format: "The local package "%s" of "%s" has no package.json."
     */
    case NATIVE_LOCAL_PACKAGE_MISSING = 'The local package "%s" of "%s" has no package.json.';

    /**
     * Error when the native manager lock file is malformed.
     *
     * Format: "The lock file "%s" is malformed; delete it and run the installation again."
     */
    case NATIVE_LOCK_INVALID = 'The lock file "%s" is malformed; delete it and run the installation again.';

    /**
     * Error when a manifest dependency field does not map package names to version strings.
     *
     * Format: "The "%s" field of "%s" must map package names to version strings."
     */
    case NATIVE_MANIFEST_DEPENDENCIES_INVALID = 'The "%s" field of "%s" must map package names to version strings.';

    /**
     * Error when the registry metadata of a package is malformed.
     *
     * Format: "The registry metadata of "%s" is malformed: %s."
     */
    case NATIVE_METADATA_INVALID = 'The registry metadata of "%s" is malformed: %s.';

    /**
     * Reason fragment when a registry version declares neither `dist.integrity` nor `dist.shasum`.
     *
     * Format: "%s must declare dist.integrity or dist.shasum"
     */
    case NATIVE_METADATA_REASON_DIST_HASH_REQUIRED = '%s must declare dist.integrity or dist.shasum';

    /**
     * Reason fragment when the registry metadata document is not valid JSON.
     *
     * Format: "the document is not valid JSON"
     */
    case NATIVE_METADATA_REASON_JSON_INVALID = 'the document is not valid JSON';

    /**
     * Reason fragment when a registry metadata member is not an object.
     *
     * Format: "%s must be a JSON object"
     */
    case NATIVE_METADATA_REASON_OBJECT_REQUIRED = '%s must be a JSON object';

    /**
     * Reason fragment when a registry metadata member does not map names to strings.
     *
     * Format: "%s must map names to strings"
     */
    case NATIVE_METADATA_REASON_STRING_MAP_REQUIRED = '%s must map names to strings';

    /**
     * Reason fragment when a registry metadata member is not a non-empty string.
     *
     * Format: "%s must be a string"
     */
    case NATIVE_METADATA_REASON_STRING_REQUIRED = '%s must be a string';

    /**
     * Error when a local `file:` package cannot be copied into `node_modules`.
     *
     * Format: "Unable to copy the local package "%s" to "%s"."
     */
    case NATIVE_PACKAGE_COPY_FAILED = 'Unable to copy the local package "%s" to "%s".';

    /**
     * Error when the registry does not know a package.
     *
     * Format: "The package "%s" was not found in the registry "%s"."
     */
    case NATIVE_PACKAGE_NOT_FOUND = 'The package "%s" was not found in the registry "%s".';

    /**
     * Error when an installed package directory cannot be removed.
     *
     * Format: "Unable to remove the package directory "%s"."
     */
    case NATIVE_PACKAGE_REMOVE_FAILED = 'Unable to remove the package directory "%s".';

    /**
     * Error when a dependency version is neither a valid npm range nor a dist-tag.
     *
     * Format: "The version "%s" of "%s" is neither a valid npm range nor a dist-tag."
     */
    case NATIVE_RANGE_INVALID = 'The version "%s" of "%s" is neither a valid npm range nor a dist-tag.';

    /**
     * Error when a registry request fails.
     *
     * Format: "The registry request "%s" failed: %s"
     */
    case NATIVE_REGISTRY_REQUEST_FAILED = 'The registry request "%s" failed: %s';

    /**
     * Error when a dependency uses a specification the native manager does not support.
     *
     * Format: "The native manager does not support the "%s" specification of "%s"; use npm, pnpm, Yarn, Bun, or Deno."
     */
    case NATIVE_SPEC_UNSUPPORTED = 'The native manager does not support the "%s" specification of "%s"; use npm, pnpm, '
        . 'Yarn, Bun, or Deno.';

    /**
     * Error when a tarball cannot be extracted.
     *
     * Format: "The tarball "%s" cannot be extracted: %s."
     */
    case NATIVE_TARBALL_INVALID = 'The tarball "%s" cannot be extracted: %s.';

    /**
     * Reason fragment when a tarball entry resolves outside the package directory.
     *
     * Format: "the entry "%s" escapes the package directory"
     */
    case NATIVE_TARBALL_REASON_ENTRY_ESCAPES = 'the entry "%s" escapes the package directory';

    /**
     * Reason fragment when a tarball header fails the ustar magic or checksum check.
     *
     * Format: "the archive is not a valid ustar archive"
     */
    case NATIVE_TARBALL_REASON_NOT_USTAR = 'the archive is not a valid ustar archive';

    /**
     * Reason fragment when a tarball ends inside a header or a data block.
     *
     * Format: "the archive is truncated"
     */
    case NATIVE_TARBALL_REASON_TRUNCATED = 'the archive is truncated';

    /**
     * Reason fragment when a tarball cannot be opened as a gzip stream.
     *
     * Format: "the archive cannot be opened"
     */
    case NATIVE_TARBALL_REASON_UNREADABLE = 'the archive cannot be opened';

    /**
     * Reason fragment when a tarball entry cannot be written to disk.
     *
     * Format: "the entry "%s" cannot be written"
     */
    case NATIVE_TARBALL_REASON_WRITE_FAILED = 'the entry "%s" cannot be written';

    /**
     * Error when no registry version satisfies every accumulated constraint of a package.
     *
     * Format: "No version of "%s" satisfies %s; install it with npm, pnpm, Yarn, Bun, or Deno."
     */
    case NATIVE_VERSION_CONFLICT = 'No version of "%s" satisfies %s; install it with npm, pnpm, Yarn, Bun, or Deno.';

    /**
     * Error when the Composer asset directory cannot be created.
     *
     * Format: "Unable to create Composer asset directory "%s"."
     */
    case SOLVER_ASSET_DIR_CREATE_FAILED = 'Unable to create Composer asset directory "%s".';

    /**
     * Error when the Composer asset directory is empty.
     *
     * Format: "The Composer asset directory must not be empty."
     */
    case SOLVER_ASSET_DIR_EMPTY = 'The Composer asset directory must not be empty.';

    /**
     * Error when the Composer asset directory cannot be inspected.
     *
     * Format: "Unable to inspect Composer asset directory "%s"."
     */
    case SOLVER_ASSET_DIR_INSPECT_FAILED = 'Unable to inspect Composer asset directory "%s".';

    /**
     * Error when the Composer asset directory is a filesystem root.
     *
     * Format: "The Composer asset directory must not be a filesystem root."
     */
    case SOLVER_ASSET_DIR_IS_ROOT = 'The Composer asset directory must not be a filesystem root.';

    /**
     * Error when the ownership marker cannot be written.
     *
     * Format: "Unable to mark Composer asset directory "%s"."
     */
    case SOLVER_ASSET_DIR_MARK_FAILED = 'Unable to mark Composer asset directory "%s".';

    /**
     * Error when "composer-asset-dir" is not a string.
     *
     * Format: "The Composer asset directory must be a string."
     */
    case SOLVER_ASSET_DIR_NOT_STRING = 'The Composer asset directory must be a string.';

    /**
     * Error when the asset directory overlaps a protected path.
     *
     * Format: "The Composer asset directory "%s" overlaps a protected project path."
     */
    case SOLVER_ASSET_DIR_OVERLAPS_PROTECTED = 'The Composer asset directory "%s" overlaps a protected project path.';

    /**
     * Error when the asset directory cannot be reset.
     *
     * Format: "Unable to reset Composer asset directory "%s"."
     */
    case SOLVER_ASSET_DIR_RESET_FAILED = 'Unable to reset Composer asset directory "%s".';

    /**
     * Error when the asset directory is a symbolic link.
     *
     * Format: "The Composer asset directory "%s" must not be a symbolic link."
     */
    case SOLVER_ASSET_DIR_SYMLINK = 'The Composer asset directory "%s" must not be a symbolic link.';

    /**
     * Error when a non-empty asset directory is not managed by Foxy.
     *
     * Format: "The Composer asset directory "%s" is not marked as managed by Foxy."
     */
    case SOLVER_ASSET_DIR_UNMANAGED = 'The Composer asset directory "%s" is not marked as managed by Foxy.';

    /**
     * Error when the asset manager ends with a non-zero code.
     *
     * Format: "The asset manager ended with error code %d."
     */
    case SOLVER_ASSET_MANAGER_FAILED = 'The asset manager ended with error code %d.';

    /**
     * Error when the asset path exists but is not a directory.
     *
     * Format: "The Composer asset path "%s" is not a directory."
     */
    case SOLVER_ASSET_PATH_NOT_DIRECTORY = 'The Composer asset path "%s" is not a directory.';

    /**
     * Error when Composer fallback restoration fails after a solve failure.
     *
     * Format: "Asset solving failed and Composer fallback restoration failed: %s"
     */
    case SOLVER_COMPOSER_FALLBACK_RESTORE_FAILED = 'Asset solving failed and Composer fallback restoration failed: %s';

    /**
     * Error when an asset manifest cannot be read.
     *
     * Format: "Unable to read asset manifest "%s"."
     */
    case SOLVER_MANIFEST_READ_FAILED = 'Unable to read asset manifest "%s".';

    /**
     * Error when an asset manifest cannot be written.
     *
     * Format: "Unable to write asset manifest "%s"."
     */
    case SOLVER_MANIFEST_WRITE_FAILED = 'Unable to write asset manifest "%s".';

    /**
     * Error when a package asset directory cannot be created.
     *
     * Format: "Unable to create asset directory "%s"."
     */
    case SOLVER_PACKAGE_DIR_CREATE_FAILED = 'Unable to create asset directory "%s".';

    /**
     * Error when a path cannot be resolved.
     *
     * Format: "Unable to resolve path "%s"."
     */
    case SOLVER_PATH_RESOLVE_FAILED = 'Unable to resolve path "%s".';

    /**
     * Error when an asset package path escapes its install directory.
     *
     * Format: "The asset package path "%s" escapes its Composer install directory."
     */
    case UTIL_ASSET_PACKAGE_PATH_ESCAPES = 'The asset package path "%s" escapes its Composer install directory.';

    /**
     * Error when a Composer package file cannot be read.
     *
     * Format: "Unable to read Composer package file "%s"."
     */
    case UTIL_COMPOSER_PACKAGE_FILE_UNREADABLE = 'Unable to read Composer package file "%s".';

    /**
     * Error when the Composer version is below the minimum.
     *
     * Format: "Foxy requires Composer "%s", but the current version is "%s"."
     */
    case UTIL_COMPOSER_VERSION_UNSUPPORTED = 'Foxy requires Composer "%s", but the current version is "%s".';

    /**
     * Returns the formatted message string for the error case.
     *
     * @param int|string ...$argument Values to insert into the message template.
     *
     * @return string Formatted error message with interpolated arguments.
     */
    public function getMessage(int|string ...$argument): string
    {
        return sprintf($this->value, ...$argument);
    }
}
