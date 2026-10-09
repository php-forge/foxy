<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Closure;
use Foxy\Audit\AuditParserInterface;
use Foxy\Audit\Parser\{BunAuditParser, NpmAuditParser, PnpmAuditParser, YarnAuditParser};
use Foxy\Exception\Message;

/**
 * Data provider for {@see \Foxy\Tests\Audit\AuditParserTest} test cases.
 */
final class AuditParserProvider
{
    /**
     * @return iterable<string, array{Closure(array<mixed>): void, string}>
     */
    public static function malformedNpmFields(): iterable
    {
        yield 'aggregate range' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['range'] = false;
            },
            self::malformed('npm', Message::AUDIT_FIELD_STRING_REQUIRED->getMessage('vulnerabilities.lodash', 'range')),
        ];
        yield 'aggregate severity' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['severity'] = 'severe';
            },
            self::malformed('npm', Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED->getMessage('vulnerabilities.lodash')),
        ];
        yield 'direct dependency flag' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['isDirect'] = 1;
            },
            self::malformed(
                'npm',
                Message::AUDIT_FIELD_BOOLEAN_REQUIRED->getMessage('vulnerabilities.lodash', 'isDirect'),
            ),
        ];
        yield 'effects list' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['effects'] = false;
            },
            self::malformed('npm', Message::AUDIT_VALUE_LIST_REQUIRED->getMessage('vulnerabilities.lodash.effects')),
        ];
        yield 'fix availability' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['fixAvailable'] = 'yes';
            },
            self::malformed('npm', Message::AUDIT_NPM_FIX_AVAILABLE_INVALID->getMessage('vulnerabilities.lodash')),
        ];
        yield 'production dependency count' => [
            static function (array &$data): void {
                $data['metadata']['dependencies']['prod'] = -1;
            },
            self::malformed(
                'npm',
                Message::AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED->getMessage('metadata.dependencies', 'prod'),
            ),
        ];
        yield 'severity count context' => [
            static function (array &$data): void {
                $data['metadata']['vulnerabilities']['high'] = -1;
            },
            self::malformed(
                'npm',
                Message::AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED->getMessage('metadata.vulnerabilities', 'high'),
            ),
        ];
        yield 'total severity count context' => [
            static function (array &$data): void {
                $data['metadata']['vulnerabilities']['total'] = -1;
            },
            self::malformed(
                'npm',
                Message::AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED->getMessage('metadata.vulnerabilities', 'total'),
            ),
        ];
        yield 'via object' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['via'] = ['advisory' => []];
            },
            self::malformed('npm', Message::AUDIT_NPM_VIA_LIST_EMPTY->getMessage('vulnerabilities.lodash')),
        ];
    }

    /**
     * @return iterable<string, array{Closure(array<mixed>): void, string}>
     */
    public static function malformedPnpmFields(): iterable
    {
        yield 'bundled dependency flag' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'][0]['bundled'] = 1;
            },
            self::malformed(
                'pnpm',
                Message::AUDIT_FIELD_BOOLEAN_REQUIRED->getMessage('advisories.1106913.findings.0', 'bundled'),
            ),
        ];
        yield 'CVE list' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = [false];
            },
            self::malformed('pnpm', Message::AUDIT_VALUE_STRINGS_ONLY->getMessage('advisories.1106913.cves')),
        ];
        yield 'CWE field' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cwe'] = false;
            },
            self::malformed('pnpm', Message::AUDIT_FIELD_STRING_REQUIRED->getMessage('advisories.1106913', 'cwe')),
        ];
        yield 'development dependency flag' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'][0]['dev'] = 1;
            },
            self::malformed(
                'pnpm',
                Message::AUDIT_FIELD_BOOLEAN_REQUIRED->getMessage('advisories.1106913.findings.0', 'dev'),
            ),
        ];
        yield 'findings object' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'] = ['finding' => []];
            },
            self::malformed('pnpm', Message::AUDIT_PNPM_FINDINGS_EMPTY->getMessage('advisories.1106913')),
        ];
        yield 'invalid CVE identifier' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = ['CVE-invalid'];
            },
            self::malformed('pnpm', Message::AUDIT_VALUE_CVE_INVALID->getMessage('advisories.1106913.cves')),
        ];
        yield 'optional dependency flag' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'][0]['optional'] = 1;
            },
            self::malformed(
                'pnpm',
                Message::AUDIT_FIELD_BOOLEAN_REQUIRED->getMessage('advisories.1106913.findings.0', 'optional'),
            ),
        ];
        yield 'prefixed CVE identifier' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = ['prefix-CVE-2021-23337'];
            },
            self::malformed('pnpm', Message::AUDIT_VALUE_CVE_INVALID->getMessage('advisories.1106913.cves')),
        ];
        yield 'suffixed CVE identifier' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = ['CVE-2021-23337-suffix'];
            },
            self::malformed('pnpm', Message::AUDIT_VALUE_CVE_INVALID->getMessage('advisories.1106913.cves')),
        ];
    }

    /**
     * @return iterable<string, array{AuditParserInterface, string, string}>
     */
    public static function malformedReports(): iterable
    {
        yield 'Bun advisories object' => [
            new BunAuditParser(),
            '{"pkg":{}}',
            self::malformed('bun', Message::AUDIT_BUN_PACKAGE_ADVISORIES_REQUIRED->getMessage()),
        ];
        yield 'Bun blank required string' => [
            new BunAuditParser(),
            '{"pkg":[{"id":1,"severity":"low","vulnerable_versions":"   "}]}',
            self::malformed('bun', Message::AUDIT_FIELD_STRING_REQUIRED->getMessage('pkg.0', 'vulnerable_versions')),
        ];
        yield 'Bun empty advisory ID' => [
            new BunAuditParser(),
            '{"pkg":[{"id":" "}]}',
            self::malformed('bun', Message::AUDIT_VALUE_EMPTY->getMessage('pkg.0.id')),
        ];
        yield 'Bun empty package key' => [
            new BunAuditParser(),
            '{"":[]}',
            self::malformed('bun', Message::AUDIT_BUN_PACKAGE_ADVISORIES_REQUIRED->getMessage()),
        ];
        yield 'Bun non-scalar advisory ID' => [
            new BunAuditParser(),
            '{"pkg":[{"id":false}]}',
            self::malformed('bun', Message::AUDIT_VALUE_STRING_OR_INTEGER_REQUIRED->getMessage('pkg.0.id')),
        ];
        yield 'Bun non-string optional URL' => [
            new BunAuditParser(),
            '{"pkg":[{"id":1,"url":false}]}',
            self::malformed('bun', Message::AUDIT_FIELD_STRING_REQUIRED->getMessage('pkg.0', 'url')),
        ];
        yield 'Bun root list' => [
            new BunAuditParser(),
            '[]',
            self::malformed('bun', Message::AUDIT_REPORT_JSON_OBJECT_EXPECTED->getMessage()),
        ];
        yield 'npm empty via' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":[],"effects":[],"range":"<1","nodes":[],"fixAvailable":false}},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_NPM_VIA_LIST_EMPTY->getMessage('vulnerabilities.pkg')),
        ];
        yield 'npm empty vulnerability key' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"":{}},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_NPM_VULNERABILITY_KEY_REQUIRED->getMessage()),
        ];
        yield 'npm error document takes precedence' => [
            new NpmAuditParser(),
            '{"error":{},"auditReportVersion":1,"vulnerabilities":[],"metadata":[]}',
            self::malformed('npm', Message::AUDIT_REPORT_ERROR_DOCUMENT->getMessage()),
        ];
        yield 'npm incomplete metadata' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('metadata.vulnerabilities')),
        ];
        yield 'npm invalid via entry' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":[false],"effects":[],"range":"<1","nodes":[],"fixAvailable":false}},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_NPM_VIA_ENTRY_INVALID->getMessage('vulnerabilities.pkg', 0)),
        ];
        yield 'npm metadata list' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":[],"metadata":[]}',
            self::malformed('npm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('metadata')),
        ];
        yield 'npm mismatched vulnerability name' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"other"}},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_NPM_NAME_KEY_MISMATCH->getMessage('vulnerabilities.pkg')),
        ];
        yield 'npm missing metadata dependencies' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0}}}',
            self::malformed('npm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('metadata.dependencies')),
        ];
        yield 'npm missing nodes' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":["dependency"],"effects":[],"range":"<1","fixAvailable":false}},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_VALUE_LIST_REQUIRED->getMessage('vulnerabilities.pkg.nodes')),
        ];
        yield 'npm v1 report' => [
            new NpmAuditParser(),
            '{"auditReportVersion":1,"vulnerabilities":{},"metadata":{}}',
            self::malformed('npm', Message::AUDIT_NPM_REPORT_VERSION_UNSUPPORTED->getMessage()),
        ];
        yield 'npm vulnerabilities list' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":[],"metadata":{}}',
            self::malformed('npm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('vulnerabilities')),
        ];
        yield 'pnpm advisories list' => [
            new PnpmAuditParser(),
            '{"advisories":[],"metadata":[]}',
            self::malformed('pnpm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('advisories')),
        ];
        yield 'pnpm empty findings' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{"id":1,"url":"","github_advisory_id":"","cwe":"","findings":[]}},"metadata":{}}',
            self::malformed('pnpm', Message::AUDIT_PNPM_FINDINGS_EMPTY->getMessage('advisories.1')),
        ];
        yield 'pnpm error document takes precedence' => [
            new PnpmAuditParser(),
            '{"error":{},"advisories":[],"metadata":[]}',
            self::malformed('pnpm', Message::AUDIT_REPORT_ERROR_DOCUMENT->getMessage()),
        ];
        yield 'pnpm incomplete metadata' => [
            new PnpmAuditParser(),
            '{"advisories":{},"metadata":{}}',
            self::malformed('pnpm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('metadata.vulnerabilities')),
        ];
        yield 'pnpm metadata list' => [
            new PnpmAuditParser(),
            '{"advisories":{},"metadata":[]}',
            self::malformed('pnpm', Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage('metadata')),
        ];
        yield 'pnpm mismatched advisory key' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{"id":2}},"metadata":{}}',
            self::malformed('pnpm', Message::AUDIT_PNPM_ID_KEY_MISMATCH->getMessage('advisories.1')),
        ];
        yield 'pnpm missing advisory id' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{}},"metadata":{}}',
            self::malformed('pnpm', Message::AUDIT_PNPM_ID_INVALID->getMessage('advisories.1')),
        ];
        yield 'pnpm missing dependency metadata' => [
            new PnpmAuditParser(),
            '{"advisories":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0}}}',
            self::malformed(
                'pnpm',
                Message::AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED->getMessage('metadata', 'dependencies'),
            ),
        ];
        yield 'pnpm missing finding paths' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{"id":1,"url":"","github_advisory_id":"","cwe":"","findings":[{"version":"1.0.0","dev":false,"optional":false,"bundled":false}]}},"metadata":{}}',
            self::malformed('pnpm', Message::AUDIT_VALUE_LIST_REQUIRED->getMessage('advisories.1.findings.0.paths')),
        ];
        yield 'unsupported severity' => [
            new BunAuditParser(),
            '{"pkg":[{"id":1,"title":"Issue","severity":"unknown","vulnerable_versions":"<1"}]}',
            self::malformed('bun', Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED->getMessage('pkg.0')),
        ];
        yield 'Yarn invalid advisory ID' => [
            new YarnAuditParser(),
            '{"value":"pkg","children":{"ID":false,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Tree Versions":[],"Dependents":[]}}',
            self::malformed('yarn', Message::AUDIT_VALUE_STRING_OR_INTEGER_REQUIRED->getMessage('line 1.children.ID')),
        ];
        yield 'Yarn invalid dependents' => [
            new YarnAuditParser(),
            '{"value":"pkg","children":{"ID":1,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Tree Versions":[],"Dependents":false}}',
            self::malformed('yarn', Message::AUDIT_VALUE_LIST_REQUIRED->getMessage('line 1.children.Dependents')),
        ];
        yield 'Yarn invalid package on second line' => [
            new YarnAuditParser(),
            "{\"value\":\"first\",\"children\":{\"ID\":1,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}\n{\"value\":false,\"children\":{\"ID\":2,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}",
            self::malformed('yarn', Message::AUDIT_FIELD_STRING_REQUIRED->getMessage('line 2', 'value')),
        ];
        yield 'Yarn malformed second line' => [
            new YarnAuditParser(),
            "{\"value\":\"pkg\",\"children\":{\"ID\":1,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}\nnot-json",
            self::malformed('yarn', Message::AUDIT_YARN_LINE_JSON_INVALID->getMessage(2)),
        ];
        yield 'Yarn missing tree versions' => [
            new YarnAuditParser(),
            '{"value":"pkg","children":{"ID":1,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Dependents":[]}}',
            self::malformed('yarn', Message::AUDIT_VALUE_LIST_REQUIRED->getMessage('line 1.children.Tree Versions')),
        ];
        yield 'Yarn non-object record' => [
            new YarnAuditParser(),
            '[]',
            self::malformed('yarn', Message::AUDIT_YARN_LINE_NOT_FINDING->getMessage(1)),
        ];
    }

    private static function malformed(string $manager, string $detail): string
    {
        return Message::AUDIT_OUTPUT_MALFORMED->getMessage($manager, $detail);
    }
}
