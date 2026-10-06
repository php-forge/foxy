<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Foxy\Audit\{AuditFinding, CveStatus, Severity};
use Foxy\Audit\Parser\DenoAuditParser;
use Foxy\Exception\RuntimeException;
use Foxy\Tests\Provider\DenoAuditParserProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function array_count_values;
use function array_map;
use function ksort;
use function sprintf;
use function str_replace;

/**
 * Unit tests for {@see DenoAuditParser} validation and normalization of the `deno audit` text report.
 *
 * {@see DenoAuditParserProvider} for test case data providers.
 */
final class DenoAuditParserTest extends TestCase
{
    use AuditFixture;

    #[DataProviderExternal(DenoAuditParserProvider::class, 'equivalentLayouts')]
    public function testParseAcceptsEquivalentLayout(string $lineEnding, string $leading, string $trailing): void
    {
        $parser = new DenoAuditParser();

        $output = $leading . str_replace("\n", $lineEnding, self::fixture('deno-populated.txt')) . $trailing;

        self::assertEquals(
            $parser->parse(self::fixture('deno-populated.txt')),
            $parser->parse($output),
            'The findings should match the findings of the original report',
        );
    }

    public function testParseKeepsNonGitHubAdvisoryUrlAsAdvisoryId(): void
    {
        $findings = (new DenoAuditParser())->parse(
            "╭ Command Injection in lodash\n"
            . "│ Severity:   high\n"
            . "│ Package:    lodash\n"
            . "│ Vulnerable: <4.17.21\n"
            . "╰ Info:       https://security.example.test/advisories/1\n"
            . "\n"
            . "Found 1 vulnerabilities\n"
            . "Severity: 0 low, 0 moderate, 1 high, 0 critical\n",
        );

        self::assertCount(
            1,
            $findings,
            'The report should contain one finding',
        );
        self::assertSame(
            'https://security.example.test/advisories/1',
            $findings[0]->advisoryId,
            'The advisory ID should fall back to the advisory URL',
        );
        self::assertSame(
            'https://security.example.test/advisories/1',
            $findings[0]->url,
            'The advisory URL should match the expected value',
        );
    }

    public function testParseReadsCleanReport(): void
    {
        self::assertSame(
            [],
            (new DenoAuditParser())->parse(self::fixture('deno-clean.txt')),
            'The clean report should not contain any finding',
        );
    }

    public function testParseReadsPatchedReport(): void
    {
        $findings = (new DenoAuditParser())->parse(self::fixture('deno-patched.txt'));

        self::assertCount(
            2,
            $findings,
            'The report should contain one finding per advisory block',
        );
        self::assertSame(
            '@denotest/with-vuln1',
            $findings[0]->package,
            'The package should match the expected value',
        );
        self::assertSame(
            Severity::HIGH,
            $findings[0]->severity,
            'The severity should match the expected value',
        );
        self::assertSame(
            '@denotest/with-vuln1 is susceptible to prototype pollution',
            $findings[0]->title,
            'The title should match the expected value',
        );
        self::assertSame(
            '<1.1.0',
            $findings[0]->vulnerableVersions,
            'The vulnerable range should match the expected value',
        );
        self::assertSame(
            'https://example.com/vuln/101010',
            $findings[0]->url,
            'The advisory URL should come from the Info line instead of the closing line',
        );
        self::assertSame(
            'https://example.com/vuln/101010',
            $findings[0]->advisoryId,
            'The advisory ID should fall back to the advisory URL',
        );
        self::assertSame(
            '@denotest/with-vuln2',
            $findings[1]->package,
            'The second package should match the expected value',
        );
        self::assertSame(
            Severity::CRITICAL,
            $findings[1]->severity,
            'The second severity should match the expected value',
        );
    }

    public function testParseReadsPopulatedReport(): void
    {
        $findings = (new DenoAuditParser())->parse(self::fixture('deno-populated.txt'));

        self::assertCount(
            8,
            $findings,
            'The report should contain one finding per advisory block',
        );

        $findingsByAdvisory = [];

        foreach ($findings as $finding) {
            $findingsByAdvisory[$finding->advisoryId] = $finding;
        }

        self::assertCount(
            8,
            $findingsByAdvisory,
            'The advisory IDs should be unique',
        );

        $severityCounts = array_count_values(
            array_map(static fn(AuditFinding $finding): string => $finding->severity->value, $findings),
        );

        ksort($severityCounts);

        self::assertSame(
            ['critical' => 1, 'high' => 3, 'moderate' => 4],
            $severityCounts,
            'The severity distribution should match the report summary',
        );
        self::assertArrayHasKey(
            'GHSA-35jh-r3h4-6jhm',
            $findingsByAdvisory,
            'The GitHub advisory ID should be extracted from the advisory URL',
        );

        $finding = $findingsByAdvisory['GHSA-35jh-r3h4-6jhm'];

        self::assertSame(
            'lodash',
            $finding->package,
            'The package should match the expected value',
        );
        self::assertSame(
            Severity::HIGH,
            $finding->severity,
            'The severity should match the expected value',
        );
        self::assertNull(
            $finding->sourceId,
            'The source ID should be `null` because the report does not provide one',
        );
        self::assertSame(
            'Command Injection in lodash',
            $finding->title,
            'The title should match the expected value',
        );
        self::assertSame(
            '<4.17.21',
            $finding->vulnerableVersions,
            'The vulnerable range should match the expected value',
        );
        self::assertSame(
            'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
            $finding->url,
            'The advisory URL should match the expected value',
        );
        self::assertSame(
            [],
            $finding->cves,
            'The CVE list should be empty because the report does not provide CVE identifiers',
        );
        self::assertSame(
            CveStatus::NOT_REQUESTED,
            $finding->cveStatus,
            'The CVE status should match the expected value',
        );
        self::assertSame(
            [],
            $finding->dependencyPaths,
            'The dependency paths should be empty because the report does not provide them',
        );
    }

    public function testParseReadsReportMixingPatchedAndUnpatchedAdvisories(): void
    {
        $findings = (new DenoAuditParser())->parse(
            "╭ Command Injection in lodash\n"
            . "│ Severity:   high\n"
            . "│ Package:    lodash\n"
            . "│ Vulnerable: <4.17.21\n"
            . "│ Patched:    >=4.17.21\n"
            . "│ Info:       https://github.com/advisories/GHSA-35jh-r3h4-6jhm\n"
            . "╰ Actions:    update lodash to >=4.17.21\n"
            . "\n"
            . "╭ Prototype Pollution in minimist\n"
            . "│ Severity:   critical\n"
            . "│ Package:    minimist\n"
            . "│ Vulnerable: <1.2.6\n"
            . "╰ Info:       https://github.com/advisories/GHSA-xvch-5gv4-984h\n"
            . "\n"
            . "Found 2 vulnerabilities\n"
            . "Severity: 0 low, 0 moderate, 1 high, 1 critical\n",
        );

        self::assertSame(
            ['GHSA-35jh-r3h4-6jhm', 'GHSA-xvch-5gv4-984h'],
            array_map(static fn(AuditFinding $finding): string => $finding->advisoryId, $findings),
            'The advisory IDs should be read from both layouts',
        );
        self::assertSame(
            ['<4.17.21', '<1.2.6'],
            array_map(static fn(AuditFinding $finding): string => $finding->vulnerableVersions, $findings),
            'The vulnerable ranges should be read from both layouts',
        );
        self::assertSame(
            [Severity::HIGH, Severity::CRITICAL],
            array_map(static fn(AuditFinding $finding): Severity => $finding->severity, $findings),
            'The severities should be read from both layouts',
        );
    }

    #[DataProviderExternal(DenoAuditParserProvider::class, 'singleAdvisoryReports')]
    public function testParseReadsSingleAdvisoryReport(string $output, Severity $severity): void
    {
        $findings = (new DenoAuditParser())->parse($output);

        self::assertCount(
            1,
            $findings,
            'The report should contain one finding',
        );
        self::assertSame(
            $severity,
            $findings[0]->severity,
            'The severity should match the expected value',
        );
        self::assertSame(
            'GHSA-35jh-r3h4-6jhm',
            $findings[0]->advisoryId,
            'The advisory ID should match the expected value',
        );
    }

    #[DataProviderExternal(DenoAuditParserProvider::class, 'malformedReports')]
    public function testThrowRuntimeExceptionForMalformedReport(string $output, string $reason): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            sprintf('The deno audit output is malformed: %s.', $reason),
        );

        (new DenoAuditParser())->parse($output);
    }
}
