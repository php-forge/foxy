<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface, CveStatus};

use function count;
use function is_int;
use function sprintf;

final class PnpmAuditParser extends AbstractAuditParser implements AuditParserInterface
{
    public function parse(string $output): array
    {
        $data = $this->decodeObject($output);

        if (isset($data['error'])) {
            throw $this->malformed('the manager returned an error document');
        }

        $advisories = $this->getObject($data['advisories'] ?? null, 'advisories');
        $metadata = $this->getObject($data['metadata'] ?? null, 'metadata');

        $findings = [];

        foreach ($advisories as $key => $advisory) {
            $context = sprintf('advisories.%s', $key);

            $advisory = $this->getObject($advisory, $context);

            $id = $advisory['id'] ?? null;

            if (!is_int($id) || $id < 0) {
                throw $this->malformed(sprintf('%s.id must be a non-negative integer', $context));
            }

            $sourceId = (string) $id;

            if ((string) $key !== $sourceId) {
                throw $this->malformed(sprintf('%s.id must match its advisory key', $context));
            }

            $url = $this->getString($advisory, 'url', $context, true);

            $url = '' === $url ? null : $url;

            $ghsaId = $this->getString($advisory, 'github_advisory_id', $context, true);

            $ghsaId = '' === $ghsaId ? null : $ghsaId;

            $this->getString($advisory, 'cwe', $context, true);

            $findingsData = $advisory['findings'] ?? null;

            if (!is_array($findingsData) || !array_is_list($findingsData) || [] === $findingsData) {
                throw $this->malformed(sprintf('%s.findings must be a non-empty list', $context));
            }

            $versions = [];
            $paths = [];

            foreach ($findingsData as $index => $finding) {
                $findingContext = sprintf('%s.findings.%d', $context, $index);

                $finding = $this->getObject($finding, $findingContext);
                $versions[] = $this->getString($finding, 'version', $findingContext);
                $paths = [...$paths, ...$this->getStringList($finding['paths'] ?? null, $findingContext . '.paths')];

                $this->getBoolean($finding, 'dev', $findingContext);
                $this->getBoolean($finding, 'optional', $findingContext);
                $this->getBoolean($finding, 'bundled', $findingContext);
            }

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

        $severityTotal = $this->getSeverityCount($metadata, 'metadata', false);

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'totalDependencies'] as $dependencyType) {
            $this->getNonNegativeInteger($metadata, $dependencyType, 'metadata');
        }

        if ($severityTotal !== count($advisories)) {
            throw $this->malformed('metadata vulnerability counts must equal the advisory entries');
        }

        return $findings;
    }

    protected function getManagerName(): string
    {
        return 'pnpm';
    }
}
