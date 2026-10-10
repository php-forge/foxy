<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Foxy\Exception\Message;

/**
 * Data provider for {@see \Foxy\Tests\Exception\MessageTest} test cases.
 */
final class MessageProvider
{
    /**
     * @return iterable<string, array{Message, list<int|string>, string}>
     */
    public static function cases(): iterable
    {
        yield 'ASSET_BUN_AUDIT_CONFIG_UNREADABLE' => [
            Message::ASSET_BUN_AUDIT_CONFIG_UNREADABLE,
            ['/project/bunfig.toml'],
            'The Bun audit configuration "/project/bunfig.toml" cannot be read.',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES' => [
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_KEY_ESCAPES,
            [],
            'escape sequences in npmrc keys are not supported; use canonical keys',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_NPMRC_OMIT_ESCAPES' => [
            Message::ASSET_BUN_AUDIT_REASON_NPMRC_OMIT_ESCAPES,
            [],
            'escape sequences in npmrc omit values are not supported; use canonical values',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_TOML_ARRAY_INSTALL_TABLE' => [
            Message::ASSET_BUN_AUDIT_REASON_TOML_ARRAY_INSTALL_TABLE,
            [],
            'array install tables are not supported; use an [install] table',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_TOML_INLINE_INSTALL_TABLE' => [
            Message::ASSET_BUN_AUDIT_REASON_TOML_INLINE_INSTALL_TABLE,
            [],
            'inline install tables are not supported; use an [install] table',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES' => [
            Message::ASSET_BUN_AUDIT_REASON_TOML_KEY_ESCAPES,
            [],
            'escape sequences in TOML keys are not supported; use canonical keys',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER' => [
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_CONTAINER,
            [],
            'multiline container values in [install] are not supported',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_STRING' => [
            Message::ASSET_BUN_AUDIT_REASON_TOML_MULTILINE_STRING,
            [],
            'multiline strings in [install] are not supported',
        ];
        yield 'ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED' => [
            Message::ASSET_BUN_AUDIT_REASON_UTF8_REQUIRED,
            [],
            'the configuration must be UTF-8',
        ];
        yield 'ASSET_BUN_AUDIT_SCOPE_RESTRICTED' => [
            Message::ASSET_BUN_AUDIT_SCOPE_RESTRICTED,
            ['/project/.npmrc', 'omit=dev'],
            'The Bun audit cannot guarantee the requested dependency scope because "/project/.npmrc" declares '
            . '"omit=dev".',
        ];
        yield 'ASSET_BUN_AUDIT_SCOPE_UNVERIFIABLE' => [
            Message::ASSET_BUN_AUDIT_SCOPE_UNVERIFIABLE,
            ['/project/bunfig.toml', 'the configuration must be UTF-8'],
            'The Bun audit cannot verify dependency scope in "/project/bunfig.toml": the configuration must be UTF-8.',
        ];
        yield 'ASSET_DENO_AUDIT_NO_DEV_UNSUPPORTED' => [
            Message::ASSET_DENO_AUDIT_NO_DEV_UNSUPPORTED,
            [],
            'The Deno audit cannot guarantee the requested dependency scope because "deno audit" cannot exclude '
            . 'development dependencies.',
        ];
        yield 'ASSET_DENO_COMPOSER_ASSET_NOT_NESTED' => [
            Message::ASSET_DENO_COMPOSER_ASSET_NOT_NESTED,
            ['/vendor/foxy/demo'],
            'The Composer asset "/vendor/foxy/demo" must be located in a subdirectory of the root package directory '
            . 'to be installed with Deno.',
        ];
        yield 'ASSET_DENO_WORKSPACES_INVALID' => [
            Message::ASSET_DENO_WORKSPACES_INVALID,
            ['/project/package.json'],
            'The "workspaces" field of "/project/package.json" must be a list of strings to install Composer assets '
            . 'with Deno.',
        ];
        yield 'ASSET_MANAGER_BINARY_NOT_INSTALLED' => [
            Message::ASSET_MANAGER_BINARY_NOT_INSTALLED,
            ['npm'],
            'The "npm" binary must be installed.',
        ];
        yield 'ASSET_MANAGER_EXITED_WITH_STATUS' => [
            Message::ASSET_MANAGER_EXITED_WITH_STATUS,
            [7],
            'The asset manager exited with status code 7.',
        ];
        yield 'ASSET_MANAGER_FALLBACK_RESTORE_FAILED' => [
            Message::ASSET_MANAGER_FALLBACK_RESTORE_FAILED,
            ['Unable to write fallback asset file "/project/package.json".'],
            'The asset manager failed and its fallback could not be restored: Unable to write fallback asset file '
            . '"/project/package.json".',
        ];
        yield 'ASSET_MANAGER_LOCKED_UNAVAILABLE' => [
            Message::ASSET_MANAGER_LOCKED_UNAVAILABLE,
            ['pnpm'],
            'The asset manager "pnpm" selected by its lock file is not available.',
        ];
        yield 'ASSET_MANAGER_LOCK_FILES_AMBIGUOUS' => [
            Message::ASSET_MANAGER_LOCK_FILES_AMBIGUOUS,
            [],
            'Multiple asset manager lock files were found; configure "manager" explicitly.',
        ];
        yield 'ASSET_MANAGER_LOCK_FILE_MISSING' => [
            Message::ASSET_MANAGER_LOCK_FILE_MISSING,
            ['npm', '/project/package-lock.json'],
            'The npm lock file "/project/package-lock.json" was not found.',
        ];
        yield 'ASSET_MANAGER_NONE_FOUND' => [
            Message::ASSET_MANAGER_NONE_FOUND,
            [],
            'No asset manager was found.',
        ];
        yield 'ASSET_MANAGER_UNKNOWN' => [
            Message::ASSET_MANAGER_UNKNOWN,
            ['gulp'],
            'The asset manager "gulp" doesn\'t exist.',
        ];
        yield 'ASSET_NPM_WORKSPACE_GRAPH_UNENUMERABLE' => [
            Message::ASSET_NPM_WORKSPACE_GRAPH_UNENUMERABLE,
            [],
            'The npm workspace graph could not be enumerated from package-lock.json. Regenerate the lock file with a '
            . 'supported npm version.',
        ];
        yield 'ASSET_ROOT_PACKAGE_DIR_MISSING' => [
            Message::ASSET_ROOT_PACKAGE_DIR_MISSING,
            ['/project/assets'],
            'The root package directory "/project/assets" doesn\'t exist.',
        ];
        yield 'ASSET_VERSION_CONSTRAINT_MISMATCH' => [
            Message::ASSET_VERSION_CONSTRAINT_MISMATCH,
            ['npm', '9.8.1', '^10.0.0'],
            'The installed npm version "9.8.1" doesn\'t match the configured version constraint "^10.0.0".',
        ];
        yield 'ASSET_VERSION_UNSUPPORTED' => [
            Message::ASSET_VERSION_UNSUPPORTED,
            ['yarn', '1.22.19', '>=2.0.0'],
            'The installed yarn version "1.22.19" doesn\'t match the supported version constraint ">=2.0.0".',
        ];
        yield 'AUDIT_BUN_PACKAGE_ADVISORIES_REQUIRED' => [
            Message::AUDIT_BUN_PACKAGE_ADVISORIES_REQUIRED,
            [],
            'each package must contain a list of advisories',
        ];
        yield 'AUDIT_BUN_PARTIAL_REPORT' => [
            Message::AUDIT_BUN_PARTIAL_REPORT,
            ['error: lockfile is outdated'],
            'The Bun audit command produced diagnostics and may have returned a partial report. error: lockfile is '
            . 'outdated',
        ];
        yield 'AUDIT_COMMAND_FAILED' => [
            Message::AUDIT_COMMAND_FAILED,
            ['npm', 7],
            'The npm audit command failed with status code 7.',
        ];
        yield 'AUDIT_COMMAND_FAILED_WITH_DIAGNOSTICS' => [
            Message::AUDIT_COMMAND_FAILED_WITH_DIAGNOSTICS,
            ['pnpm', 1, 'ERR_PNPM_AUDIT_BAD_RESPONSE'],
            'The pnpm audit command failed with status code 1. ERR_PNPM_AUDIT_BAD_RESPONSE',
        ];
        yield 'AUDIT_DENO_ADVISORY_ACTIONS_MISMATCH' => [
            Message::AUDIT_DENO_ADVISORY_ACTIONS_MISMATCH,
            ['advisory 2'],
            'advisory 2 actions must update the package to its patched versions',
        ];
        yield 'AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID' => [
            Message::AUDIT_DENO_ADVISORY_LINE_COUNT_INVALID,
            ['advisory 1', 6, 7],
            'advisory 1 must contain 6 or 7 lines',
        ];
        yield 'AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED' => [
            Message::AUDIT_DENO_ADVISORY_LINE_UNRECOGNIZED,
            ['advisory 3', 4],
            'advisory 3 line 4 is not recognized',
        ];
        yield 'AUDIT_DENO_REPORT_EMPTY' => [
            Message::AUDIT_DENO_REPORT_EMPTY,
            [],
            'the report is empty',
        ];
        yield 'AUDIT_DENO_REPORT_NO_ADVISORY' => [
            Message::AUDIT_DENO_REPORT_NO_ADVISORY,
            [],
            'the report does not contain any advisory',
        ];
        yield 'AUDIT_DENO_SUMMARY_SEVERITY_MISMATCH' => [
            Message::AUDIT_DENO_SUMMARY_SEVERITY_MISMATCH,
            [2, 'high', 1],
            'the summary reports 2 high vulnerabilities but the report contains 1',
        ];
        yield 'AUDIT_DENO_SUMMARY_TOTAL_MISMATCH' => [
            Message::AUDIT_DENO_SUMMARY_TOTAL_MISMATCH,
            [3, 2],
            'the summary reports 3 vulnerabilities but the report contains 2 advisories',
        ];
        yield 'AUDIT_DENO_SUMMARY_UNRECOGNIZED' => [
            Message::AUDIT_DENO_SUMMARY_UNRECOGNIZED,
            [],
            'the report does not end with a recognized summary',
        ];
        yield 'AUDIT_FIELD_BOOLEAN_REQUIRED' => [
            Message::AUDIT_FIELD_BOOLEAN_REQUIRED,
            ['vulnerabilities.lodash', 'isDirect'],
            'vulnerabilities.lodash.isDirect must be a boolean',
        ];
        yield 'AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED' => [
            Message::AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED,
            ['metadata.vulnerabilities', 'high'],
            'metadata.vulnerabilities.high must be a non-negative integer',
        ];
        yield 'AUDIT_FIELD_STRING_REQUIRED' => [
            Message::AUDIT_FIELD_STRING_REQUIRED,
            ['vulnerabilities.lodash', 'severity'],
            'vulnerabilities.lodash.severity must be a string',
        ];
        yield 'AUDIT_GHSA_ID_INVALID' => [
            Message::AUDIT_GHSA_ID_INVALID,
            ['GHSA-invalid'],
            'The advisory identifier "GHSA-invalid" is not a valid GHSA identifier.',
        ];
        yield 'AUDIT_GITHUB_ADVISORY_INVALID' => [
            Message::AUDIT_GITHUB_ADVISORY_INVALID,
            ['GHSA-35jh-r3h4-6jhm'],
            'GitHub returned an invalid advisory document for GHSA-35jh-r3h4-6jhm.',
        ];
        yield 'AUDIT_GITHUB_ADVISORY_MISMATCHED' => [
            Message::AUDIT_GITHUB_ADVISORY_MISMATCHED,
            ['GHSA-35jh-r3h4-6jhm'],
            'GitHub returned a mismatched advisory document for GHSA-35jh-r3h4-6jhm.',
        ];
        yield 'AUDIT_GITHUB_IDENTIFIERS_INVALID' => [
            Message::AUDIT_GITHUB_IDENTIFIERS_INVALID,
            ['GHSA-35jh-r3h4-6jhm'],
            'GitHub returned invalid identifiers for GHSA-35jh-r3h4-6jhm.',
        ];
        yield 'AUDIT_MANAGER_ERROR_APPENDED' => [
            Message::AUDIT_MANAGER_ERROR_APPENDED,
            ['The npm audit output is malformed: invalid JSON.', 'npm ERR! code ENOLOCK'],
            'The npm audit output is malformed: invalid JSON. Manager error: npm ERR! code ENOLOCK',
        ];
        yield 'AUDIT_NPM_FIX_AVAILABLE_INVALID' => [
            Message::AUDIT_NPM_FIX_AVAILABLE_INVALID,
            ['vulnerabilities.lodash'],
            'vulnerabilities.lodash.fixAvailable must be a boolean or object',
        ];
        yield 'AUDIT_NPM_METADATA_COUNT_MISMATCH' => [
            Message::AUDIT_NPM_METADATA_COUNT_MISMATCH,
            [],
            'metadata vulnerability counts must equal the vulnerability entries',
        ];
        yield 'AUDIT_NPM_NAME_KEY_MISMATCH' => [
            Message::AUDIT_NPM_NAME_KEY_MISMATCH,
            ['vulnerabilities.lodash'],
            'vulnerabilities.lodash.name must match its vulnerability key',
        ];
        yield 'AUDIT_NPM_REPORT_VERSION_UNSUPPORTED' => [
            Message::AUDIT_NPM_REPORT_VERSION_UNSUPPORTED,
            [],
            'auditReportVersion must be 2',
        ];
        yield 'AUDIT_NPM_VIA_ENTRY_INVALID' => [
            Message::AUDIT_NPM_VIA_ENTRY_INVALID,
            ['vulnerabilities.lodash', 0],
            'vulnerabilities.lodash.via.0 must be a string or object',
        ];
        yield 'AUDIT_NPM_VIA_LIST_EMPTY' => [
            Message::AUDIT_NPM_VIA_LIST_EMPTY,
            ['vulnerabilities.lodash'],
            'vulnerabilities.lodash.via must be a non-empty list',
        ];
        yield 'AUDIT_NPM_VULNERABILITY_KEY_REQUIRED' => [
            Message::AUDIT_NPM_VULNERABILITY_KEY_REQUIRED,
            [],
            'each vulnerability must be keyed by a package name',
        ];
        yield 'AUDIT_OUTPUT_MALFORMED' => [
            Message::AUDIT_OUTPUT_MALFORMED,
            ['npm', 'invalid JSON'],
            'The npm audit output is malformed: invalid JSON.',
        ];
        yield 'AUDIT_PARSER_UNSUPPORTED_MANAGER' => [
            Message::AUDIT_PARSER_UNSUPPORTED_MANAGER,
            ['deno'],
            'The asset manager "deno" does not provide a supported audit report.',
        ];
        yield 'AUDIT_PNPM_FINDINGS_EMPTY' => [
            Message::AUDIT_PNPM_FINDINGS_EMPTY,
            ['advisories.1096'],
            'advisories.1096.findings must be a non-empty list',
        ];
        yield 'AUDIT_PNPM_ID_INVALID' => [
            Message::AUDIT_PNPM_ID_INVALID,
            ['advisories.1096'],
            'advisories.1096.id must be a non-negative integer',
        ];
        yield 'AUDIT_PNPM_ID_KEY_MISMATCH' => [
            Message::AUDIT_PNPM_ID_KEY_MISMATCH,
            ['advisories.1096'],
            'advisories.1096.id must match its advisory key',
        ];
        yield 'AUDIT_PNPM_METADATA_COUNT_MISMATCH' => [
            Message::AUDIT_PNPM_METADATA_COUNT_MISMATCH,
            [],
            'metadata vulnerability counts must equal the advisory entries',
        ];
        yield 'AUDIT_REPORT_ERROR_DOCUMENT' => [
            Message::AUDIT_REPORT_ERROR_DOCUMENT,
            [],
            'the manager returned an error document',
        ];
        yield 'AUDIT_REPORT_JSON_INVALID' => [
            Message::AUDIT_REPORT_JSON_INVALID,
            [],
            'invalid JSON',
        ];
        yield 'AUDIT_REPORT_JSON_OBJECT_EXPECTED' => [
            Message::AUDIT_REPORT_JSON_OBJECT_EXPECTED,
            [],
            'expected a JSON object',
        ];
        yield 'AUDIT_REPORT_SIZE_LIMIT_EXCEEDED' => [
            Message::AUDIT_REPORT_SIZE_LIMIT_EXCEEDED,
            [],
            'the report exceeds the 16 MiB safety limit',
        ];
        yield 'AUDIT_SEVERITY_TOTAL_MISMATCH' => [
            Message::AUDIT_SEVERITY_TOTAL_MISMATCH,
            ['metadata'],
            'metadata.vulnerabilities.total must equal the severity counts',
        ];
        yield 'AUDIT_SUCCESS_STATUS_WITH_FINDINGS' => [
            Message::AUDIT_SUCCESS_STATUS_WITH_FINDINGS,
            ['yarn'],
            'The yarn audit report contains vulnerabilities but the manager returned a successful status.',
        ];
        yield 'AUDIT_VALUE_CVE_INVALID' => [
            Message::AUDIT_VALUE_CVE_INVALID,
            ['advisories.1096.cves'],
            'advisories.1096.cves contains an invalid CVE identifier',
        ];
        yield 'AUDIT_VALUE_EMPTY' => [
            Message::AUDIT_VALUE_EMPTY,
            ['vulnerabilities.lodash.name'],
            'vulnerabilities.lodash.name must not be empty',
        ];
        yield 'AUDIT_VALUE_LIST_REQUIRED' => [
            Message::AUDIT_VALUE_LIST_REQUIRED,
            ['advisories.1096.cves'],
            'advisories.1096.cves must be a list',
        ];
        yield 'AUDIT_VALUE_OBJECT_REQUIRED' => [
            Message::AUDIT_VALUE_OBJECT_REQUIRED,
            ['metadata'],
            'metadata must be an object',
        ];
        yield 'AUDIT_VALUE_SEVERITY_UNSUPPORTED' => [
            Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED,
            ['vulnerabilities.lodash'],
            'vulnerabilities.lodash has an unsupported severity',
        ];
        yield 'AUDIT_VALUE_STRINGS_ONLY' => [
            Message::AUDIT_VALUE_STRINGS_ONLY,
            ['advisories.1096.cves'],
            'advisories.1096.cves must contain only strings',
        ];
        yield 'AUDIT_VALUE_STRING_OR_INTEGER_REQUIRED' => [
            Message::AUDIT_VALUE_STRING_OR_INTEGER_REQUIRED,
            ['advisories.1096.id'],
            'advisories.1096.id must be a string or integer',
        ];
        yield 'AUDIT_YARN_LINE_JSON_INVALID' => [
            Message::AUDIT_YARN_LINE_JSON_INVALID,
            [3],
            'line 3 contains invalid JSON',
        ];
        yield 'AUDIT_YARN_LINE_NOT_FINDING' => [
            Message::AUDIT_YARN_LINE_NOT_FINDING,
            [5],
            'line 5 is not an audit finding',
        ];
        yield 'COMMAND_CAPABILITY_ARGUMENTS_INVALID' => [
            Message::COMMAND_CAPABILITY_ARGUMENTS_INVALID,
            [],
            'Composer provided invalid Foxy command capability arguments.',
        ];
        yield 'CONFIG_ENV_JSON_INVALID' => [
            Message::CONFIG_ENV_JSON_INVALID,
            ['FOXY__MANAGER_OPTIONS'],
            'The "FOXY__MANAGER_OPTIONS" environment variable isn\'t valid JSON.',
        ];
        yield 'CURRENT_WORKING_DIRECTORY_UNAVAILABLE' => [
            Message::CURRENT_WORKING_DIRECTORY_UNAVAILABLE,
            [],
            'Unable to get the current working directory.',
        ];
        yield 'FALLBACK_ASSET_PATH_NOT_FILE' => [
            Message::FALLBACK_ASSET_PATH_NOT_FILE,
            ['/project/package.json'],
            'The fallback asset path "/project/package.json" must be a regular file.',
        ];
        yield 'FALLBACK_ASSET_READ_FAILED' => [
            Message::FALLBACK_ASSET_READ_FAILED,
            ['/project/package.json'],
            'Unable to read fallback asset file "/project/package.json".',
        ];
        yield 'FALLBACK_ASSET_REMOVE_FAILED' => [
            Message::FALLBACK_ASSET_REMOVE_FAILED,
            ['/project/package.json'],
            'Unable to remove fallback asset file "/project/package.json".',
        ];
        yield 'FALLBACK_ASSET_WRITE_FAILED' => [
            Message::FALLBACK_ASSET_WRITE_FAILED,
            ['/project/package.json'],
            'Unable to write fallback asset file "/project/package.json".',
        ];
        yield 'FALLBACK_COMPOSER_REMOVE_FAILED' => [
            Message::FALLBACK_COMPOSER_REMOVE_FAILED,
            ['/project/vendor/foxy/demo'],
            'Unable to remove Composer fallback path "/project/vendor/foxy/demo".',
        ];
        yield 'FALLBACK_COMPOSER_RESTORE_FAILED' => [
            Message::FALLBACK_COMPOSER_RESTORE_FAILED,
            [7],
            'Unable to restore Composer dependencies, installer exited with code 7.',
        ];
        yield 'FOXY_AUDIT_DISABLED' => [
            Message::FOXY_AUDIT_DISABLED,
            [],
            'Foxy is disabled; frontend dependencies cannot be audited.',
        ];
        yield 'FOXY_AUDIT_UNSUPPORTED_MANAGER' => [
            Message::FOXY_AUDIT_UNSUPPORTED_MANAGER,
            [],
            'The selected asset manager does not support security audits.',
        ];
        yield 'JSON_FILE_UNREADABLE' => [
            Message::JSON_FILE_UNREADABLE,
            ['/project/package.json'],
            'Unable to read JSON file "/project/package.json".',
        ];
        yield 'NATIVE_EXTENSION_MISSING' => [
            Message::NATIVE_EXTENSION_MISSING,
            ['zlib'],
            'The native manager requires the "zlib" PHP extension.',
        ];
        yield 'NATIVE_INSTALL_DIR_INVALID' => [
            Message::NATIVE_INSTALL_DIR_INVALID,
            ['/project'],
            'The native install directory "/project" must not be the root package directory, one of its parents, or a '
            . 'filesystem root.',
        ];
        yield 'NATIVE_INTEGRITY_MISMATCH' => [
            Message::NATIVE_INTEGRITY_MISMATCH,
            ['https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz'],
            'The tarball "https://registry.npmjs.org/bootstrap/-/bootstrap-5.3.8.tgz" failed its integrity check.',
        ];
        yield 'NATIVE_LOCAL_PACKAGE_MISSING' => [
            Message::NATIVE_LOCAL_PACKAGE_MISSING,
            ['./vendor/acme/theme', '@composer-asset/acme--theme'],
            'The local package "./vendor/acme/theme" of "@composer-asset/acme--theme" has no package.json.',
        ];
        yield 'NATIVE_LOCAL_VERSION_CONFLICT' => [
            Message::NATIVE_LOCAL_VERSION_CONFLICT,
            ['@composer-asset/acme--theme', '1.0.0', '"^2.0" from acme@1.0.0'],
            'The local package "@composer-asset/acme--theme" (1.0.0) does not satisfy "^2.0" from acme@1.0.0; install it '
            . 'with npm, pnpm, Yarn, Bun, or Deno.',
        ];
        yield 'NATIVE_LOCK_INVALID' => [
            Message::NATIVE_LOCK_INVALID,
            ['/project/foxy.lock'],
            'The lock file "/project/foxy.lock" is malformed; delete it and run the installation again.',
        ];
        yield 'NATIVE_MANIFEST_DEPENDENCIES_INVALID' => [
            Message::NATIVE_MANIFEST_DEPENDENCIES_INVALID,
            ['dependencies', '/project/package.json'],
            'The "dependencies" field of "/project/package.json" must map package names to version strings.',
        ];
        yield 'NATIVE_METADATA_INVALID' => [
            Message::NATIVE_METADATA_INVALID,
            ['bootstrap', 'versions must be a JSON object'],
            'The registry metadata of "bootstrap" is malformed: versions must be a JSON object.',
        ];
        yield 'NATIVE_METADATA_REASON_DIST_HASH_REQUIRED' => [
            Message::NATIVE_METADATA_REASON_DIST_HASH_REQUIRED,
            ['versions.5.3.8'],
            'versions.5.3.8 must declare dist.integrity or dist.shasum',
        ];
        yield 'NATIVE_METADATA_REASON_JSON_INVALID' => [
            Message::NATIVE_METADATA_REASON_JSON_INVALID,
            [],
            'the document is not valid JSON',
        ];
        yield 'NATIVE_METADATA_REASON_OBJECT_REQUIRED' => [
            Message::NATIVE_METADATA_REASON_OBJECT_REQUIRED,
            ['versions'],
            'versions must be a JSON object',
        ];
        yield 'NATIVE_METADATA_REASON_STRING_MAP_REQUIRED' => [
            Message::NATIVE_METADATA_REASON_STRING_MAP_REQUIRED,
            ['dist-tags'],
            'dist-tags must map names to strings',
        ];
        yield 'NATIVE_METADATA_REASON_STRING_REQUIRED' => [
            Message::NATIVE_METADATA_REASON_STRING_REQUIRED,
            ['versions.5.3.8.dist.tarball'],
            'versions.5.3.8.dist.tarball must be a string',
        ];
        yield 'NATIVE_PACKAGE_COPY_FAILED' => [
            Message::NATIVE_PACKAGE_COPY_FAILED,
            ['/project/vendor/acme/theme', '/project/node_modules/@composer-asset/acme--theme'],
            'Unable to copy the local package "/project/vendor/acme/theme" to '
            . '"/project/node_modules/@composer-asset/acme--theme".',
        ];
        yield 'NATIVE_PACKAGE_NOT_FOUND' => [
            Message::NATIVE_PACKAGE_NOT_FOUND,
            ['@acme/missing', 'https://registry.npmjs.org'],
            'The package "@acme/missing" was not found in the registry "https://registry.npmjs.org".',
        ];
        yield 'NATIVE_PACKAGE_REMOVE_FAILED' => [
            Message::NATIVE_PACKAGE_REMOVE_FAILED,
            ['/project/node_modules/bootstrap'],
            'Unable to remove the package directory "/project/node_modules/bootstrap".',
        ];
        yield 'NATIVE_RANGE_INVALID' => [
            Message::NATIVE_RANGE_INVALID,
            ['>=1, <2', 'bootstrap'],
            'The version ">=1, <2" of "bootstrap" is neither a valid npm range nor a dist-tag.',
        ];
        yield 'NATIVE_REGISTRY_REQUEST_FAILED' => [
            Message::NATIVE_REGISTRY_REQUEST_FAILED,
            ['https://registry.npmjs.org/bootstrap', 'HTTP/1.1 500 Internal Server Error'],
            'The registry request "https://registry.npmjs.org/bootstrap" failed: HTTP/1.1 500 Internal Server Error',
        ];
        yield 'NATIVE_SPEC_UNSUPPORTED' => [
            Message::NATIVE_SPEC_UNSUPPORTED,
            ['github:twbs/bootstrap', 'bootstrap'],
            'The native manager does not support the "github:twbs/bootstrap" specification of "bootstrap"; '
                . 'use npm, pnpm, Yarn, Bun, or Deno.',
        ];
        yield 'NATIVE_TARBALL_INVALID' => [
            Message::NATIVE_TARBALL_INVALID,
            ['bootstrap-5.3.8.tgz', 'the archive is truncated'],
            'The tarball "bootstrap-5.3.8.tgz" cannot be extracted: the archive is truncated.',
        ];
        yield 'NATIVE_TARBALL_REASON_ENTRY_ESCAPES' => [
            Message::NATIVE_TARBALL_REASON_ENTRY_ESCAPES,
            ['package/../../etc/passwd'],
            'the entry "package/../../etc/passwd" escapes the package directory',
        ];
        yield 'NATIVE_TARBALL_REASON_NOT_USTAR' => [
            Message::NATIVE_TARBALL_REASON_NOT_USTAR,
            [],
            'the archive is not a valid ustar archive',
        ];
        yield 'NATIVE_TARBALL_REASON_TRUNCATED' => [
            Message::NATIVE_TARBALL_REASON_TRUNCATED,
            [],
            'the archive is truncated',
        ];
        yield 'NATIVE_TARBALL_REASON_UNREADABLE' => [
            Message::NATIVE_TARBALL_REASON_UNREADABLE,
            [],
            'the archive cannot be opened',
        ];
        yield 'NATIVE_TARBALL_REASON_WRITE_FAILED' => [
            Message::NATIVE_TARBALL_REASON_WRITE_FAILED,
            ['dist/css/bootstrap.css'],
            'the entry "dist/css/bootstrap.css" cannot be written',
        ];
        yield 'NATIVE_VERSION_CONFLICT' => [
            Message::NATIVE_VERSION_CONFLICT,
            ['bootstrap', '"^5.0" from root, "^4.0" from acme@1.0.0'],
            'No version of "bootstrap" satisfies "^5.0" from root, "^4.0" from acme@1.0.0; install it with npm, pnpm, '
                . 'Yarn, Bun, or Deno.',
        ];
        yield 'SOLVER_ASSET_DIR_CREATE_FAILED' => [
            Message::SOLVER_ASSET_DIR_CREATE_FAILED,
            ['/project/vendor/foxy/composer-asset'],
            'Unable to create Composer asset directory "/project/vendor/foxy/composer-asset".',
        ];
        yield 'SOLVER_ASSET_DIR_EMPTY' => [
            Message::SOLVER_ASSET_DIR_EMPTY,
            [],
            'The Composer asset directory must not be empty.',
        ];
        yield 'SOLVER_ASSET_DIR_INSPECT_FAILED' => [
            Message::SOLVER_ASSET_DIR_INSPECT_FAILED,
            ['/project/vendor/foxy/composer-asset'],
            'Unable to inspect Composer asset directory "/project/vendor/foxy/composer-asset".',
        ];
        yield 'SOLVER_ASSET_DIR_IS_ROOT' => [
            Message::SOLVER_ASSET_DIR_IS_ROOT,
            [],
            'The Composer asset directory must not be a filesystem root.',
        ];
        yield 'SOLVER_ASSET_DIR_MARK_FAILED' => [
            Message::SOLVER_ASSET_DIR_MARK_FAILED,
            ['/project/vendor/foxy/composer-asset'],
            'Unable to mark Composer asset directory "/project/vendor/foxy/composer-asset".',
        ];
        yield 'SOLVER_ASSET_DIR_NOT_STRING' => [
            Message::SOLVER_ASSET_DIR_NOT_STRING,
            [],
            'The Composer asset directory must be a string.',
        ];
        yield 'SOLVER_ASSET_DIR_OVERLAPS_PROTECTED' => [
            Message::SOLVER_ASSET_DIR_OVERLAPS_PROTECTED,
            ['/project/vendor'],
            'The Composer asset directory "/project/vendor" overlaps a protected project path.',
        ];
        yield 'SOLVER_ASSET_DIR_RESET_FAILED' => [
            Message::SOLVER_ASSET_DIR_RESET_FAILED,
            ['/project/vendor/foxy/composer-asset'],
            'Unable to reset Composer asset directory "/project/vendor/foxy/composer-asset".',
        ];
        yield 'SOLVER_ASSET_DIR_SYMLINK' => [
            Message::SOLVER_ASSET_DIR_SYMLINK,
            ['/project/vendor/foxy/composer-asset'],
            'The Composer asset directory "/project/vendor/foxy/composer-asset" must not be a symbolic link.',
        ];
        yield 'SOLVER_ASSET_DIR_UNMANAGED' => [
            Message::SOLVER_ASSET_DIR_UNMANAGED,
            ['/project/vendor/foxy/composer-asset'],
            'The Composer asset directory "/project/vendor/foxy/composer-asset" is not marked as managed by Foxy.',
        ];
        yield 'SOLVER_ASSET_MANAGER_FAILED' => [
            Message::SOLVER_ASSET_MANAGER_FAILED,
            [7],
            'The asset manager ended with error code 7.',
        ];
        yield 'SOLVER_ASSET_PATH_NOT_DIRECTORY' => [
            Message::SOLVER_ASSET_PATH_NOT_DIRECTORY,
            ['/project/vendor/foxy/composer-asset/foxy/demo'],
            'The Composer asset path "/project/vendor/foxy/composer-asset/foxy/demo" is not a directory.',
        ];
        yield 'SOLVER_COMPOSER_FALLBACK_RESTORE_FAILED' => [
            Message::SOLVER_COMPOSER_FALLBACK_RESTORE_FAILED,
            ['Unable to restore Composer dependencies, installer exited with code 7.'],
            'Asset solving failed and Composer fallback restoration failed: Unable to restore Composer dependencies, '
            . 'installer exited with code 7.',
        ];
        yield 'SOLVER_MANIFEST_READ_FAILED' => [
            Message::SOLVER_MANIFEST_READ_FAILED,
            ['/project/vendor/foxy/demo/package.json'],
            'Unable to read asset manifest "/project/vendor/foxy/demo/package.json".',
        ];
        yield 'SOLVER_MANIFEST_WRITE_FAILED' => [
            Message::SOLVER_MANIFEST_WRITE_FAILED,
            ['/project/vendor/foxy/demo/package.json'],
            'Unable to write asset manifest "/project/vendor/foxy/demo/package.json".',
        ];
        yield 'SOLVER_PACKAGE_DIR_CREATE_FAILED' => [
            Message::SOLVER_PACKAGE_DIR_CREATE_FAILED,
            ['/project/vendor/foxy/composer-asset/foxy/demo'],
            'Unable to create asset directory "/project/vendor/foxy/composer-asset/foxy/demo".',
        ];
        yield 'SOLVER_PATH_RESOLVE_FAILED' => [
            Message::SOLVER_PATH_RESOLVE_FAILED,
            ['/project/missing'],
            'Unable to resolve path "/project/missing".',
        ];
        yield 'UTIL_ASSET_PACKAGE_PATH_ESCAPES' => [
            Message::UTIL_ASSET_PACKAGE_PATH_ESCAPES,
            ['../outside'],
            'The asset package path "../outside" escapes its Composer install directory.',
        ];
        yield 'UTIL_COMPOSER_PACKAGE_FILE_UNREADABLE' => [
            Message::UTIL_COMPOSER_PACKAGE_FILE_UNREADABLE,
            ['/project/vendor/foxy/demo/composer.json'],
            'Unable to read Composer package file "/project/vendor/foxy/demo/composer.json".',
        ];
        yield 'UTIL_COMPOSER_VERSION_UNSUPPORTED' => [
            Message::UTIL_COMPOSER_VERSION_UNSUPPORTED,
            ['2.4.0', '2.3.10'],
            'Foxy requires Composer "2.4.0", but the current version is "2.3.10".',
        ];
    }
}
