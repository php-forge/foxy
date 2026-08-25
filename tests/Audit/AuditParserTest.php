<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\{AuditParserFactory, AuditParserInterface};
use Foxy\Audit\Parser\{BunAuditParser, NpmAuditParser, PnpmAuditParser, YarnAuditParser};
use Foxy\Audit\Severity;
use Foxy\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

final class AuditParserTest extends TestCase
{
    use AuditFixture;

    /**
     * Provides invalid manager output for strict parser tests.
     *
     * @return array<string, array{AuditParserInterface, string, string}>
     */
    public static function getMalformedReportData(): array
    {
        return [
            'npm v1 report' => [
                new NpmAuditParser(),
                '{"auditReportVersion":1,"vulnerabilities":{},"metadata":{}}',
                'auditReportVersion must be 2 and metadata must be an object',
            ],
            'npm invalid via entry' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":[false],"effects":[],"range":"<1","nodes":[],"fixAvailable":false}},"metadata":{}}',
                'via.0 must be a string or object',
            ],
            'npm metadata list' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":{},"metadata":[]}',
                'metadata must be an object',
            ],
            'npm vulnerabilities list' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":[],"metadata":{}}',
                'vulnerabilities must be an object',
            ],
            'npm incomplete metadata' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{}}',
                'metadata.vulnerabilities must be an object',
            ],
            'npm missing metadata dependencies' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0,"total":0}}}',
                'metadata.dependencies must be an object',
            ],
            'npm empty via' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":[],"effects":[],"range":"<1","nodes":[],"fixAvailable":false}},"metadata":{}}',
                'via must be a non-empty list',
            ],
            'npm missing nodes' => [
                new NpmAuditParser(),
                '{"auditReportVersion":2,"vulnerabilities":{"pkg":{"name":"pkg","severity":"low","isDirect":true,"via":["dependency"],"effects":[],"range":"<1","fixAvailable":false}},"metadata":{}}',
                'nodes must be a list',
            ],
            'pnpm mismatched advisory key' => [
                new PnpmAuditParser(),
                '{"advisories":{"1":{"id":2}},"metadata":{}}',
                'id must match its advisory key',
            ],
            'pnpm advisories list' => [
                new PnpmAuditParser(),
                '{"advisories":[],"metadata":{}}',
                'advisories must be an object',
            ],
            'pnpm metadata list' => [
                new PnpmAuditParser(),
                '{"advisories":{},"metadata":[]}',
                'metadata must be an object',
            ],
            'pnpm incomplete metadata' => [
                new PnpmAuditParser(),
                '{"advisories":{},"metadata":{}}',
                'metadata.vulnerabilities must be an object',
            ],
            'pnpm missing dependency metadata' => [
                new PnpmAuditParser(),
                '{"advisories":{},"metadata":{"vulnerabilities":{"info":0,"low":0,"moderate":0,"high":0,"critical":0}}}',
                'metadata.dependencies must be a non-negative integer',
            ],
            'pnpm missing advisory id' => [
                new PnpmAuditParser(),
                '{"advisories":{"1":{}},"metadata":{}}',
                'advisories.1.id must be a non-negative integer',
            ],
            'pnpm empty findings' => [
                new PnpmAuditParser(),
                '{"advisories":{"1":{"id":1,"url":"","github_advisory_id":"","cwe":"","findings":[]}},"metadata":{}}',
                'findings must be a non-empty list',
            ],
            'pnpm missing finding paths' => [
                new PnpmAuditParser(),
                '{"advisories":{"1":{"id":1,"url":"","github_advisory_id":"","cwe":"","findings":[{"version":"1.0.0","dev":false,"optional":false,"bundled":false}]}},"metadata":{}}',
                'paths must be a list',
            ],
            'Yarn malformed second line' => [
                new YarnAuditParser(),
                "{\"value\":\"pkg\",\"children\":{\"ID\":1,\"Issue\":\"Issue\",\"Severity\":\"low\",\"Vulnerable Versions\":\"<1\",\"Tree Versions\":[],\"Dependents\":[]}}\nnot-json",
                'line 2 contains invalid JSON',
            ],
            'Yarn missing tree versions' => [
                new YarnAuditParser(),
                '{"value":"pkg","children":{"ID":1,"Issue":"Issue","Severity":"low","Vulnerable Versions":"<1","Dependents":[]}}',
                'Tree Versions must be a list',
            ],
            'Bun root list' => [
                new BunAuditParser(),
                '[]',
                'expected a JSON object',
            ],
            'unsupported severity' => [
                new BunAuditParser(),
                '{"pkg":[{"id":1,"title":"Issue","severity":"unknown","vulnerable_versions":"<1"}]}',
                'has an unsupported severity',
            ],
        ];
    }

    public function testBunParserReadsRawBulkAuditReport(): void
    {
        $findings = (new BunAuditParser())->parse(self::fixture('bun-populated.json'));

        self::assertCount(2, $findings);
        self::assertSame('lodash', $findings[0]->package);
        self::assertSame(Severity::HIGH, $findings[0]->severity);
        self::assertSame('GHSA-35jh-r3h4-6jhm', $findings[0]->advisoryId);
        self::assertSame('1106913', $findings[0]->sourceId);
        self::assertSame([], $findings[0]->affectedVersions);
        self::assertSame([], $findings[0]->dependencyPaths);
        self::assertSame('1107000', $findings[1]->advisoryId);
        self::assertSame(Severity::INFO, $findings[1]->severity);
        self::assertSame('Vulnerability found', $findings[1]->title);
    }

    public function testNpmParserEmitsOnlyConcreteViaAdvisories(): void
    {
        $findings = (new NpmAuditParser())->parse(self::fixture('npm-populated.json'));

        self::assertCount(2, $findings);
        self::assertSame('lodash', $findings[0]->package);
        self::assertSame(Severity::HIGH, $findings[0]->severity);
        self::assertSame('GHSA-35jh-r3h4-6jhm', $findings[0]->advisoryId);
        self::assertSame('1106913', $findings[0]->sourceId);
        self::assertSame('Prototype pollution in lodash', $findings[0]->title);
        self::assertSame('<4.17.21', $findings[0]->vulnerableVersions);
        self::assertSame(
            ['node_modules/lodash', 'node_modules/parent/node_modules/lodash'],
            $findings[0]->dependencyPaths,
        );
        self::assertSame('GHSA-jf85-cpcp-j695', $findings[1]->advisoryId);
        self::assertSame('1108258', $findings[1]->sourceId);
        self::assertSame(Severity::CRITICAL, $findings[1]->severity);
        self::assertSame('Vulnerability found', $findings[1]->title);
    }

    public function testNpmParserRejectsInconsistentMetadataCounts(): void
    {
        $data = json_decode(self::fixture('npm-populated.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['metadata']['vulnerabilities']['critical'] = 2;
        $data['metadata']['vulnerabilities']['total'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('metadata vulnerability counts must equal the vulnerability entries');

        (new NpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testNpmParserRejectsInconsistentMetadataTotal(): void
    {
        $data = json_decode(self::fixture('npm-populated.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['metadata']['vulnerabilities']['total'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('metadata.vulnerabilities.total must equal the severity counts');

        (new NpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testParserFactoryCreatesEverySupportedParser(): void
    {
        self::assertInstanceOf(NpmAuditParser::class, AuditParserFactory::create('npm'));
        self::assertInstanceOf(PnpmAuditParser::class, AuditParserFactory::create('pnpm'));
        self::assertInstanceOf(YarnAuditParser::class, AuditParserFactory::create('yarn'));
        self::assertInstanceOf(BunAuditParser::class, AuditParserFactory::create('bun'));
    }

    public function testParserFactoryRejectsUnsupportedManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The asset manager "legacy" does not provide a supported audit report.');

        AuditParserFactory::create('legacy');
    }

    public function testParserRejectsReportAboveSafetyLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the report exceeds the 16 MiB safety limit');

        (new NpmAuditParser())->parse('{' . str_repeat(' ', 16 * 1024 * 1024) . '}');
    }

    public function testParsersReadCleanReports(): void
    {
        self::assertSame([], (new NpmAuditParser())->parse(self::fixture('npm-clean.json')));
        self::assertSame([], (new PnpmAuditParser())->parse(self::fixture('pnpm-clean.json')));
        self::assertSame([], (new YarnAuditParser())->parse(self::fixture('yarn-clean.ndjson')));
        self::assertSame([], (new BunAuditParser())->parse(self::fixture('bun-clean.json')));
    }

    #[DataProvider('getMalformedReportData')]
    public function testParsersRejectMalformedReports(
        AuditParserInterface $parser,
        string $output,
        string $expectedMessage,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $parser->parse($output);
    }

    public function testPnpmParserReadsInstalledVersionsAndPaths(): void
    {
        $findings = (new PnpmAuditParser())->parse(self::fixture('pnpm-populated.json'));

        self::assertCount(2, $findings);
        self::assertSame('lodash', $findings[0]->package);
        self::assertSame(Severity::HIGH, $findings[0]->severity);
        self::assertSame('GHSA-35jh-r3h4-6jhm', $findings[0]->advisoryId);
        self::assertSame('1106913', $findings[0]->sourceId);
        self::assertSame(['4.17.19', '4.17.20'], $findings[0]->affectedVersions);
        self::assertSame(['project>lodash', 'project>parent>lodash'], $findings[0]->dependencyPaths);
        self::assertSame('1107000', $findings[1]->advisoryId);
        self::assertSame(['1.0.0'], $findings[1]->affectedVersions);
        self::assertSame([], $findings[1]->dependencyPaths);
    }

    public function testPnpmParserRejectsInconsistentMetadataCounts(): void
    {
        $data = json_decode(self::fixture('pnpm-populated.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['metadata']['vulnerabilities']['high'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('metadata vulnerability counts must equal the advisory entries');

        (new PnpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testYarnParserReadsEveryNdjsonRecord(): void
    {
        $findings = (new YarnAuditParser())->parse(self::fixture('yarn-populated.ndjson'));

        self::assertCount(2, $findings);
        self::assertSame('@scope/package', $findings[0]->package);
        self::assertSame(Severity::MODERATE, $findings[0]->severity);
        self::assertSame('GHSA-35jh-r3h4-6jhm', $findings[0]->advisoryId);
        self::assertSame('1089254', $findings[0]->sourceId);
        self::assertSame(['1.2.5', '1.2.6'], $findings[0]->affectedVersions);
        self::assertSame(
            ['parent@npm:2.0.3', 'workspace@workspace:.'],
            $findings[0]->dependencyPaths,
        );
        self::assertSame('1107000', $findings[1]->advisoryId);
        self::assertSame(Severity::INFO, $findings[1]->severity);
    }
}
