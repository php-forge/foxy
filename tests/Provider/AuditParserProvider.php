<?php

declare(strict_types=1);

namespace Foxy\Tests\Provider;

use Closure;
use Foxy\Audit\AuditParserInterface;
use Foxy\Audit\Parser\{BunAuditParser, NpmAuditParser, PnpmAuditParser, YarnAuditParser};

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
            'vulnerabilities.lodash.range must be a string',
        ];
        yield 'aggregate severity' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['severity'] = 'severe';
            },
            'vulnerabilities.lodash has an unsupported severity',
        ];
        yield 'direct dependency flag' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['isDirect'] = 1;
            },
            'vulnerabilities.lodash.isDirect must be a boolean',
        ];
        yield 'effects list' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['effects'] = false;
            },
            'vulnerabilities.lodash.effects must be a list',
        ];
        yield 'fix availability' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['fixAvailable'] = 'yes';
            },
            'vulnerabilities.lodash.fixAvailable must be a boolean or object',
        ];
        yield 'production dependency count' => [
            static function (array &$data): void {
                $data['metadata']['dependencies']['prod'] = -1;
            },
            'metadata.dependencies.prod must be a non-negative integer',
        ];
        yield 'severity count context' => [
            static function (array &$data): void {
                $data['metadata']['vulnerabilities']['high'] = -1;
            },
            'metadata.vulnerabilities.high must be a non-negative integer',
        ];
        yield 'total severity count context' => [
            static function (array &$data): void {
                $data['metadata']['vulnerabilities']['total'] = -1;
            },
            'metadata.vulnerabilities.total must be a non-negative integer',
        ];
        yield 'via object' => [
            static function (array &$data): void {
                $data['vulnerabilities']['lodash']['via'] = ['advisory' => []];
            },
            'vulnerabilities.lodash.via must be a non-empty list',
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
            'advisories.1106913.findings.0.bundled must be a boolean',
        ];
        yield 'CVE list' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = [false];
            },
            'advisories.1106913.cves must contain only strings',
        ];
        yield 'CWE field' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cwe'] = false;
            },
            'advisories.1106913.cwe must be a string',
        ];
        yield 'development dependency flag' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'][0]['dev'] = 1;
            },
            'advisories.1106913.findings.0.dev must be a boolean',
        ];
        yield 'findings object' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'] = ['finding' => []];
            },
            'advisories.1106913.findings must be a non-empty list',
        ];
        yield 'invalid CVE identifier' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = ['CVE-invalid'];
            },
            'advisories.1106913.cves contains an invalid CVE identifier',
        ];
        yield 'optional dependency flag' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['findings'][0]['optional'] = 1;
            },
            'advisories.1106913.findings.0.optional must be a boolean',
        ];
        yield 'prefixed CVE identifier' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = ['prefix-CVE-2021-23337'];
            },
            'advisories.1106913.cves contains an invalid CVE identifier',
        ];
        yield 'suffixed CVE identifier' => [
            static function (array &$data): void {
                $data['advisories']['1106913']['cves'] = ['CVE-2021-23337-suffix'];
            },
            'advisories.1106913.cves contains an invalid CVE identifier',
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
            'each package must contain a list of advisories',
        ];
        yield 'Bun blank required string' => [
            new BunAuditParser(),
            '{"pkg":[{"id":1,"severity":"low","vulnerable_versions":"   "}]}',
            'pkg.0.vulnerable_versions must be a string',
        ];
        yield 'Bun empty advisory ID' => [
            new BunAuditParser(),
            '{"pkg":[{"id":" "}]}',
            'pkg.0.id must not be empty',
        ];
        yield 'Bun empty package key' => [
            new BunAuditParser(),
            '{"":[]}',
            'each package must contain a list of advisories',
        ];
        yield 'Bun non-scalar advisory ID' => [
            new BunAuditParser(),
            '{"pkg":[{"id":false}]}',
            'pkg.0.id must be a string or integer',
        ];
        yield 'Bun non-string optional URL' => [
            new BunAuditParser(),
            '{"pkg":[{"id":1,"url":false}]}',
            'pkg.0.url must be a string',
        ];
        yield 'Bun root list' => [
            new BunAuditParser(),
            '[]',
            'expected a JSON object',
        ];
        yield 'npm empty via' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":[],"effects":[],"range":"<1","nodes":[],"fixAvailable":false}},"metadata":{}}',
            'via must be a non-empty list',
        ];
        yield 'npm empty vulnerability key' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"":{}},"metadata":{}}',
            'each vulnerability must be keyed by a package name',
        ];
        yield 'npm error document takes precedence' => [
            new NpmAuditParser(),
            '{"error":{},"auditReportVersion":1,"vulnerabilities":[],"metadata":[]}',
            'the manager returned an error document',
        ];
        yield 'npm incomplete metadata' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{}}',
            'metadata.vulnerabilities must be an object',
        ];
        yield 'npm invalid via entry' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":[false],"effects":[],"range":"<1","nodes":[],"fixAvailable":false}},"metadata":{}}',
            'via.0 must be a string or object',
        ];
        yield 'npm metadata list' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":[],"metadata":[]}',
            'metadata must be an object',
        ];
        yield 'npm mismatched vulnerability name' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"other"}},"metadata":{}}',
            'vulnerabilities.pkg.name must match its vulnerability key',
        ];
        yield 'npm missing metadata dependencies' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0}}}',
            'metadata.dependencies must be an object',
        ];
        yield 'npm missing nodes' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":["dependency"],"effects":[],"range":"<1","fixAvailable":false}},"metadata":{}}',
            'nodes must be a list',
        ];
        yield 'npm v1 report' => [
            new NpmAuditParser(),
            '{"auditReportVersion":1,"vulnerabilities":{},"metadata":{}}',
            'auditReportVersion must be 2',
        ];
        yield 'npm vulnerabilities list' => [
            new NpmAuditParser(),
            '{"auditReportVersion":2,"vulnerabilities":[],"metadata":{}}',
            'vulnerabilities must be an object',
        ];
        yield 'pnpm advisories list' => [
            new PnpmAuditParser(),
            '{"advisories":[],"metadata":[]}',
            'advisories must be an object',
        ];
        yield 'pnpm empty findings' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{"id":1,"url":"","github_advisory_id":"","cwe":"","findings":[]}},"metadata":{}}',
            'findings must be a non-empty list',
        ];
        yield 'pnpm error document takes precedence' => [
            new PnpmAuditParser(),
            '{"error":{},"advisories":[],"metadata":[]}',
            'the manager returned an error document',
        ];
        yield 'pnpm incomplete metadata' => [
            new PnpmAuditParser(),
            '{"advisories":{},"metadata":{}}',
            'metadata.vulnerabilities must be an object',
        ];
        yield 'pnpm metadata list' => [
            new PnpmAuditParser(),
            '{"advisories":{},"metadata":[]}',
            'metadata must be an object',
        ];
        yield 'pnpm mismatched advisory key' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{"id":2}},"metadata":{}}',
            'id must match its advisory key',
        ];
        yield 'pnpm missing advisory id' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{}},"metadata":{}}',
            'advisories.1.id must be a non-negative integer',
        ];
        yield 'pnpm missing dependency metadata' => [
            new PnpmAuditParser(),
            '{"advisories":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0}}}',
            'metadata.dependencies must be a non-negative integer',
        ];
        yield 'pnpm missing finding paths' => [
            new PnpmAuditParser(),
            '{"advisories":{"1":{"id":1,"url":"","github_advisory_id":"","cwe":"","findings":[{"version":"1.0.0","dev":false,"optional":false,"bundled":false}]}},"metadata":{}}',
            'advisories.1.findings.0.paths must be a list',
        ];
        yield 'unsupported severity' => [
            new BunAuditParser(),
            '{"pkg":[{"id":1,"title":"Issue","severity":"unknown","vulnerable_versions":"<1"}]}',
            'has an unsupported severity',
        ];
        yield 'Yarn invalid advisory ID' => [
            new YarnAuditParser(),
            '{"value":"pkg","children":{"ID":false,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Tree Versions":[],"Dependents":[]}}',
            'line 1.children.ID must be a string or integer',
        ];
        yield 'Yarn invalid dependents' => [
            new YarnAuditParser(),
            '{"value":"pkg","children":{"ID":1,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Tree Versions":[],"Dependents":false}}',
            'line 1.children.Dependents must be a list',
        ];
        yield 'Yarn invalid package on second line' => [
            new YarnAuditParser(),
            "{\"value\":\"first\",\"children\":{\"ID\":1,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}\n{\"value\":false,\"children\":{\"ID\":2,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}",
            'line 2.value must be a string',
        ];
        yield 'Yarn malformed second line' => [
            new YarnAuditParser(),
            "{\"value\":\"pkg\",\"children\":{\"ID\":1,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}\nnot-json",
            'line 2 contains invalid JSON',
        ];
        yield 'Yarn missing tree versions' => [
            new YarnAuditParser(),
            '{"value":"pkg","children":{"ID":1,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Dependents":[]}}',
            'line 1.children.Tree Versions must be a list',
        ];
        yield 'Yarn non-object record' => [
            new YarnAuditParser(),
            '[]',
            'line 1 is not an audit finding',
        ];
    }
}
