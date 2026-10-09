<?php

declare(strict_types=1);

namespace Foxy\Tests\Audit;

use Closure;
use Foxy\Audit\{AuditParserFactory, AuditParserInterface, CveStatus};
use Foxy\Audit\Parser\{BunAuditParser, DenoAuditParser, NpmAuditParser, PnpmAuditParser, YarnAuditParser};
use Foxy\Audit\Severity;
use Foxy\Exception\{Message, RuntimeException};
use Foxy\Tests\Provider\AuditParserProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for {@see AuditParserFactory} and the npm, pnpm, Yarn, Bun, and Deno audit report parsers.
 *
 * {@see AuditParserProvider} for test case data providers.
 */
final class AuditParserTest extends TestCase
{
    use AuditFixture;

    public function testBunParserAcceptsCaseInsensitiveSeverity(): void
    {
        $data = json_decode(self::fixture('bun-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $data['lodash'][0]['severity'] = 'HIGH';

        $findings = (new BunAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertSame(
            Severity::HIGH,
            $findings[0]->severity,
            'The uppercase severity should map to the matching level',
        );
    }

    public function testBunParserAcceptsSurroundingWhitespace(): void
    {
        $output = "\n " . self::fixture('bun-clean.json') . " \n";

        self::assertSame(
            [],
            (new BunAuditParser())->parse($output),
            'The padded clean report should not contain any finding',
        );
    }

    public function testBunParserNormalizesBlankOptionalUrl(): void
    {
        $data = json_decode(self::fixture('bun-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $data['lodash'][0]['url'] = '   ';

        $findings = (new BunAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertNull(
            $findings[0]->url,
            'The blank URL should be normalized to `null`',
        );
        self::assertSame(
            '1106913',
            $findings[0]->advisoryId,
            'The advisory ID should fall back to the source ID without a URL',
        );
    }

    public function testBunParserReadsNumericPackageName(): void
    {
        $findings = (new BunAuditParser())->parse(
            '{"0":[{"id":1,"severity":"low","vulnerable_versions":"<1"}]}',
        );

        self::assertSame(
            '0',
            $findings[0]->package,
            'The numeric package key should be kept as a string',
        );
    }

    public function testBunParserReadsRawBulkAuditReport(): void
    {
        $findings = (new BunAuditParser())->parse(self::fixture('bun-populated.json'));

        self::assertCount(
            2,
            $findings,
            'The report should contain one finding per advisory',
        );
        self::assertSame(
            'lodash',
            $findings[0]->package,
            'The package should match the expected value',
        );
        self::assertSame(
            Severity::HIGH,
            $findings[0]->severity,
            'The severity should match the expected value',
        );
        self::assertSame(
            'GHSA-35jh-r3h4-6jhm',
            $findings[0]->advisoryId,
            'The GitHub advisory ID should be extracted from the advisory URL',
        );
        self::assertSame(
            '1106913',
            $findings[0]->sourceId,
            'The integer advisory ID should become the string source ID',
        );
        self::assertSame(
            'Command Injection in lodash',
            $findings[0]->title,
            'The title should match the expected value',
        );
        self::assertSame(
            [],
            $findings[0]->affectedVersions,
            'The affected versions should be empty because the report does not provide them',
        );
        self::assertSame(
            [],
            $findings[0]->dependencyPaths,
            'The dependency paths should be empty because the report does not provide them',
        );
        self::assertSame(
            '1107000',
            $findings[1]->advisoryId,
            'The advisory ID should fall back to the source ID for a non-GitHub URL',
        );
        self::assertSame(
            Severity::INFO,
            $findings[1]->severity,
            'The second severity should match the expected value',
        );
        self::assertSame(
            'Vulnerability found',
            $findings[1]->title,
            'The missing title should fall back to the default title',
        );
    }

    public function testNpmParserAcceptsNumericPackageKeyAndObjectFixAvailability(): void
    {
        $data = json_decode(self::fixture('npm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $vulnerability = $data['vulnerabilities']['lodash'];
        $vulnerability['name'] = '0';
        $vulnerability['fixAvailable'] = [
            'name' => 'lodash',
            'version' => '4.17.21',
            'isSemVerMajor' => false,
        ];
        $data['vulnerabilities'] = (object) ['0' => $vulnerability];

        $findings = (new NpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertCount(
            2,
            $findings,
            'The report should contain one finding per advisory object',
        );
        self::assertSame(
            '0',
            $findings[0]->package,
            'The numeric package key should be kept as a string',
        );
        self::assertSame(
            '0',
            $findings[1]->package,
            'Every finding should carry the numeric package key',
        );
    }

    public function testNpmParserEmitsOnlyConcreteViaAdvisories(): void
    {
        $findings = (new NpmAuditParser())->parse(self::fixture('npm-populated.json'));

        self::assertCount(
            2,
            $findings,
            'The report should contain one finding per advisory object',
        );
        self::assertSame(
            'lodash',
            $findings[0]->package,
            'The package should match the expected value',
        );
        self::assertSame(
            Severity::HIGH,
            $findings[0]->severity,
            'The advisory severity should take precedence over the aggregate severity',
        );
        self::assertSame(
            'GHSA-35jh-r3h4-6jhm',
            $findings[0]->advisoryId,
            'The GitHub advisory ID should be extracted from the advisory URL',
        );
        self::assertSame(
            '1106913',
            $findings[0]->sourceId,
            'The integer source should become the string source ID',
        );
        self::assertSame(
            'Command Injection in lodash',
            $findings[0]->title,
            'The title whitespace should be normalized',
        );
        self::assertSame(
            '<4.17.21',
            $findings[0]->vulnerableVersions,
            'The vulnerable range should come from the advisory instead of the aggregate entry',
        );
        self::assertSame(
            ['node_modules/lodash', 'node_modules/parent/node_modules/lodash'],
            $findings[0]->dependencyPaths,
            'The dependency paths should be the unique installed nodes',
        );
        self::assertSame(
            'GHSA-jf85-cpcp-j695',
            $findings[1]->advisoryId,
            'The second GitHub advisory ID should be extracted from its URL',
        );
        self::assertSame(
            '1108258',
            $findings[1]->sourceId,
            'The string source should be kept as the source ID',
        );
        self::assertSame(
            Severity::CRITICAL,
            $findings[1]->severity,
            'The second severity should match the expected value',
        );
        self::assertSame(
            'Vulnerability found',
            $findings[1]->title,
            'The missing title should fall back to the default title',
        );
    }

    public function testParserFactoryCreatesEverySupportedParser(): void
    {
        self::assertInstanceOf(
            NpmAuditParser::class,
            AuditParserFactory::create('npm'),
            'The `npm` manager should resolve to the npm parser',
        );
        self::assertInstanceOf(
            PnpmAuditParser::class,
            AuditParserFactory::create('pnpm'),
            'The `pnpm` manager should resolve to the pnpm parser',
        );
        self::assertInstanceOf(
            YarnAuditParser::class,
            AuditParserFactory::create('yarn'),
            'The `yarn` manager should resolve to the Yarn parser',
        );
        self::assertInstanceOf(
            BunAuditParser::class,
            AuditParserFactory::create('bun'),
            'The `bun` manager should resolve to the Bun parser',
        );
        self::assertInstanceOf(
            DenoAuditParser::class,
            AuditParserFactory::create('deno'),
            'The `deno` manager should resolve to the Deno parser',
        );
    }

    public function testParsersReadCleanReports(): void
    {
        self::assertSame(
            [],
            (new NpmAuditParser())->parse(self::fixture('npm-clean.json')),
            'The clean npm report should not contain any finding',
        );
        self::assertSame(
            [],
            (new PnpmAuditParser())->parse(self::fixture('pnpm-clean.json')),
            'The clean pnpm report should not contain any finding',
        );
        self::assertSame(
            [],
            (new YarnAuditParser())->parse(self::fixture('yarn-clean.ndjson')),
            'The clean Yarn report should not contain any finding',
        );
        self::assertSame(
            [],
            (new BunAuditParser())->parse(self::fixture('bun-clean.json')),
            'The clean Bun report should not contain any finding',
        );
        self::assertSame(
            [],
            (new DenoAuditParser())->parse(self::fixture('deno-clean.txt')),
            'The clean Deno report should not contain any finding',
        );
    }

    public function testPnpmParserAcceptsZeroIdAndPreservesOptionalHeaderValuesAndCves(): void
    {
        $data = json_decode(self::fixture('pnpm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $advisory = $data['advisories']['1106913'];
        $advisory['id'] = 0;
        $advisory['title'] = '';
        $advisory['url'] = 'https://security.example.test/advisories/0';
        $advisory['cves'] = ['cve-2021-23337', 'CVE-2021-23337'];
        $data['advisories'] = (object) ['0' => $advisory];
        $data['metadata']['vulnerabilities']['info'] = 0;

        $findings = (new PnpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertCount(
            1,
            $findings,
            'The report should contain the single remaining advisory',
        );
        self::assertSame(
            '0',
            $findings[0]->sourceId,
            'The zero ID should be kept as the source ID',
        );
        self::assertSame(
            'GHSA-35jh-r3h4-6jhm',
            $findings[0]->advisoryId,
            'The advisory ID should come from the explicit GitHub advisory ID',
        );
        self::assertSame(
            '',
            $findings[0]->title,
            'The empty title should be preserved',
        );
        self::assertSame(
            'https://security.example.test/advisories/0',
            $findings[0]->url,
            'The non-GitHub URL should be preserved',
        );
        self::assertSame(
            ['CVE-2021-23337'],
            $findings[0]->cves,
            'The CVE identifiers should be uppercased and deduplicated',
        );
        self::assertSame(
            CveStatus::RESOLVED,
            $findings[0]->cveStatus,
            'The CVE status should be resolved when the report lists CVE identifiers',
        );
    }

    public function testPnpmParserPrefersExplicitGhsaIdOverAdvisoryUrl(): void
    {
        $data = json_decode(self::fixture('pnpm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $data['advisories']['1106913']['github_advisory_id'] = 'GHSA-2222-3333-4444';

        $findings = (new PnpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertSame(
            'GHSA-2222-3333-4444',
            $findings[0]->advisoryId,
            'The advisory ID should match the expected value',
        );
    }

    public function testPnpmParserReadsInstalledVersionsAndPaths(): void
    {
        $findings = (new PnpmAuditParser())->parse(self::fixture('pnpm-populated.json'));

        self::assertCount(
            2,
            $findings,
            'The report should contain one finding per advisory',
        );
        self::assertSame(
            'lodash',
            $findings[0]->package,
            'The package should come from the module name',
        );
        self::assertSame(
            Severity::HIGH,
            $findings[0]->severity,
            'The severity should match the expected value',
        );
        self::assertSame(
            'GHSA-35jh-r3h4-6jhm',
            $findings[0]->advisoryId,
            'The uppercase GitHub advisory ID should be normalized',
        );
        self::assertSame(
            '1106913',
            $findings[0]->sourceId,
            'The source ID should match the advisory key',
        );
        self::assertSame(
            'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
            $findings[0]->url,
            'The advisory URL should match the expected value',
        );
        self::assertSame(
            CveStatus::NOT_REQUESTED,
            $findings[0]->cveStatus,
            'The CVE status should be not requested when the report lists no CVE identifiers',
        );
        self::assertSame(
            ['4.17.19', '4.17.20'],
            $findings[0]->affectedVersions,
            'The affected versions should be the sorted installed versions',
        );
        self::assertSame(
            ['project>lodash', 'project>parent>lodash'],
            $findings[0]->dependencyPaths,
            'The dependency paths should be the unique finding paths',
        );
        self::assertSame(
            '1107000',
            $findings[1]->advisoryId,
            'The advisory ID should fall back to the source ID without a GitHub reference',
        );
        self::assertSame(
            ['1.0.0'],
            $findings[1]->affectedVersions,
            'The second affected versions should match the installed version',
        );
        self::assertSame(
            [],
            $findings[1]->dependencyPaths,
            'The second dependency paths should be empty because the finding lists none',
        );
    }

    #[DataProviderExternal(AuditParserProvider::class, 'malformedNpmFields')]
    public function testThrowRuntimeExceptionForMalformedNpmField(Closure $mutate, string $expectedMessage): void
    {
        $data = json_decode(self::fixture('npm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $mutate($data);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $expectedMessage,
        );

        (new NpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[DataProviderExternal(AuditParserProvider::class, 'malformedPnpmFields')]
    public function testThrowRuntimeExceptionForMalformedPnpmField(Closure $mutate, string $expectedMessage): void
    {
        $data = json_decode(self::fixture('pnpm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $mutate($data);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $expectedMessage,
        );

        (new PnpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[DataProviderExternal(AuditParserProvider::class, 'malformedReports')]
    public function testThrowRuntimeExceptionForMalformedReport(
        AuditParserInterface $parser,
        string $output,
        string $expectedMessage,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $expectedMessage,
        );

        $parser->parse($output);
    }

    public function testThrowRuntimeExceptionForUnsupportedManager(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_PARSER_UNSUPPORTED_MANAGER->getMessage('legacy'),
        );

        AuditParserFactory::create('legacy');
    }

    public function testThrowRuntimeExceptionWhenNpmMetadataCountsMismatchEntries(): void
    {
        $data = json_decode(self::fixture('npm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $data['metadata']['vulnerabilities']['critical'] = 2;
        $data['metadata']['vulnerabilities']['total'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage(
                'npm',
                Message::AUDIT_NPM_METADATA_COUNT_MISMATCH->getMessage(),
            ),
        );

        (new NpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testThrowRuntimeExceptionWhenNpmMetadataTotalMismatchesSeverityCounts(): void
    {
        $data = json_decode(self::fixture('npm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $data['metadata']['vulnerabilities']['total'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage(
                'npm',
                Message::AUDIT_SEVERITY_TOTAL_MISMATCH->getMessage('metadata'),
            ),
        );

        (new NpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testThrowRuntimeExceptionWhenPnpmMetadataCountsMismatchAdvisories(): void
    {
        $data = json_decode(self::fixture('pnpm-populated.json'), true, 512, JSON_THROW_ON_ERROR);

        $data['metadata']['vulnerabilities']['high'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage(
                'pnpm',
                Message::AUDIT_PNPM_METADATA_COUNT_MISMATCH->getMessage(),
            ),
        );

        (new PnpmAuditParser())->parse(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testThrowRuntimeExceptionWhenReportAtSafetyLimitHasUnsupportedVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage(
                'npm',
                Message::AUDIT_NPM_REPORT_VERSION_UNSUPPORTED->getMessage(),
            ),
        );

        (new NpmAuditParser())->parse('{' . str_repeat(' ', 16 * 1024 * 1024 - 2) . '}');
    }

    public function testThrowRuntimeExceptionWhenReportExceedsSafetyLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage('npm', Message::AUDIT_REPORT_SIZE_LIMIT_EXCEEDED->getMessage()),
        );

        (new NpmAuditParser())->parse('{' . str_repeat(' ', 16 * 1024 * 1024) . '}');
    }

    public function testThrowRuntimeExceptionWhenYarnReportExceedsSafetyLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage(
                'yarn',
                Message::AUDIT_REPORT_SIZE_LIMIT_EXCEEDED->getMessage(),
            ),
        );

        (new YarnAuditParser())->parse(str_repeat(' ', 16 * 1024 * 1024 + 1));
    }

    public function testYarnParserReadsEveryNdjsonRecord(): void
    {
        $findings = (new YarnAuditParser())->parse(self::fixture('yarn-populated.ndjson'));

        self::assertCount(
            2,
            $findings,
            'The report should contain one finding per NDJSON line',
        );
        self::assertSame(
            '@scope/package',
            $findings[0]->package,
            'The scoped package should match the expected value',
        );
        self::assertSame(
            Severity::MODERATE,
            $findings[0]->severity,
            'The severity should match the expected value',
        );
        self::assertSame(
            'GHSA-2222-3333-4444',
            $findings[0]->advisoryId,
            'The GitHub advisory ID should be extracted from the advisory URL',
        );
        self::assertSame(
            '1089254',
            $findings[0]->sourceId,
            'The integer ID should become the string source ID',
        );
        self::assertSame(
            ['1.2.5', '1.2.6'],
            $findings[0]->affectedVersions,
            'The tree versions should be unique and sorted',
        );
        self::assertSame(
            ['parent@npm:2.0.3', 'workspace@workspace:.'],
            $findings[0]->dependencyPaths,
            'The dependents should be sorted',
        );
        self::assertSame(
            '1107000',
            $findings[1]->advisoryId,
            'The advisory ID should fall back to the source ID without a URL',
        );
        self::assertSame(
            Severity::INFO,
            $findings[1]->severity,
            'The second severity should match the expected value',
        );
    }
}
