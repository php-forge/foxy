<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface, CveStatus};
use Foxy\Exception\Message;

use function array_push;
use function count;
use function is_array;
use function is_int;
use function sprintf;

final class PnpmAuditParser extends AbstractAuditParser implements AuditParserInterface
{
    public function parse(string $output): array
    {
        $data = $this->decodeObject($output);

        $this->assertNotErrorDocument($data);

        $advisories = $this->getObject($data['advisories'] ?? null, 'advisories');
        $metadata = $this->getObject($data['metadata'] ?? null, 'metadata');

        $findings = [];

        foreach ($advisories as $key => $advisory) {
            $context = sprintf('advisories.%s', $key);

            $advisory = $this->getObject($advisory, $context);

            ['sourceId' => $sourceId, 'url' => $url, 'ghsaId' => $ghsaId] = $this->parseAdvisoryHeader(
                $advisory,
                $key,
                $context,
            );

            [$versions, $paths] = $this->parseFindings($advisory, $context);

            $cves = $this->getCves($advisory['cves'] ?? null, $context . '.cves');

            $findings[] = new AuditFinding(
                $this->getString($advisory, 'module_name', $context),
                $this->getSeverity($advisory['severity'] ?? null, $context),
                $this->getAdvisoryId($sourceId, $ghsaId, $url),
                $sourceId,
                $this->getString($advisory, 'title', $context, true),
                $this->getString($advisory, 'vulnerable_versions', $context),
                $url,
                $cves,
                [] === $cves ? CveStatus::NOT_REQUESTED : CveStatus::RESOLVED,
                affectedVersions: $this->uniqueStrings($versions),
                dependencyPaths: $this->uniqueStrings($paths),
            );
        }

        $this->validateMetadata($metadata, count($advisories));

        return $findings;
    }

    protected function getManagerName(): string
    {
        return 'pnpm';
    }

    /**
     * @param array<mixed> $data
     */
    private function getNonEmptyStringOrNull(array $data, string $key, string $context): string|null
    {
        $value = $this->getString($data, $key, $context, true);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<mixed> $advisory
     *
     * @return array{sourceId: string, url: string|null, ghsaId: string|null}
     */
    private function parseAdvisoryHeader(array $advisory, int|string $key, string $context): array
    {
        $id = $advisory['id'] ?? null;

        if (!is_int($id) || $id < 0) {
            throw $this->malformed(
                Message::AUDIT_PNPM_ID_INVALID->getMessage($context),
            );
        }

        $sourceId = (string) $id;

        if ((string) $key !== $sourceId) {
            throw $this->malformed(
                Message::AUDIT_PNPM_ID_KEY_MISMATCH->getMessage($context),
            );
        }

        $url = $this->getNonEmptyStringOrNull($advisory, 'url', $context);
        $ghsaId = $this->getNonEmptyStringOrNull($advisory, 'github_advisory_id', $context);

        $this->getString($advisory, 'cwe', $context, true);

        return ['sourceId' => $sourceId, 'url' => $url, 'ghsaId' => $ghsaId];
    }

    /**
     * @param array<mixed> $advisory
     *
     * @return array{list<string>, list<string>}
     */
    private function parseFindings(array $advisory, string $context): array
    {
        $findingsData = $advisory['findings'] ?? null;

        if (!is_array($findingsData) || [] === $findingsData) {
            throw $this->malformed(
                Message::AUDIT_PNPM_FINDINGS_EMPTY->getMessage($context),
            );
        }

        $versions = [];
        $paths = [];

        foreach ($findingsData as $index => $finding) {
            $findingContext = sprintf('%s.findings.%d', $context, $index);

            $finding = $this->getObject($finding, $findingContext);
            $versions[] = $this->getString($finding, 'version', $findingContext);

            array_push($paths, ...$this->getStringList($finding['paths'] ?? null, $findingContext . '.paths'));

            $this->getBoolean($finding, 'dev', $findingContext);
            $this->getBoolean($finding, 'optional', $findingContext);
            $this->getBoolean($finding, 'bundled', $findingContext);
        }

        return [$versions, $paths];
    }

    /**
     * @param array<mixed> $metadata
     */
    private function validateMetadata(array $metadata, int $advisoryCount): void
    {
        $severityTotal = $this->getSeverityCount($metadata, 'metadata', false);

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'totalDependencies'] as $dependencyType) {
            $this->getNonNegativeInteger($metadata, $dependencyType, 'metadata');
        }

        if ($severityTotal !== $advisoryCount) {
            throw $this->malformed(
                Message::AUDIT_PNPM_METADATA_COUNT_MISMATCH->getMessage(),
            );
        }
    }
}
