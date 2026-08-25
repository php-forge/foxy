<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface};
use stdClass;

use function count;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

final class NpmAuditParser extends AbstractAuditParser implements AuditParserInterface
{
    public function parse(string $output): array
    {
        $data = $this->decodeObject($output);

        $this->validateReportHeader($data);

        $metadata = $this->getObject($data['metadata'] ?? null, 'metadata');
        $vulnerabilities = $this->getObject($data['vulnerabilities'] ?? null, 'vulnerabilities');

        $findings = [];

        foreach ($vulnerabilities as $package => $vulnerability) {
            foreach ($this->parseVulnerability($package, $vulnerability) as $finding) {
                $findings[] = $finding;
            }
        }

        $this->validateMetadata($metadata, count($vulnerabilities));

        return $findings;
    }

    protected function getManagerName(): string
    {
        return 'npm';
    }

    /**
     * @param list<mixed>  $via
     * @param list<string> $paths
     *
     * @return list<AuditFinding>
     */
    private function parseViaAdvisories(array $via, string $package, array $paths, string $context): array
    {
        $findings = [];

        foreach ($via as $index => $advisory) {
            if (is_string($advisory)) {
                continue;
            }

            if (!$advisory instanceof stdClass) {
                throw $this->malformed(sprintf('%s.via.%d must be a string or object', $context, $index));
            }

            $advisoryContext = sprintf('%s.via.%d', $context, $index);

            $advisory = $this->getObject($advisory, $advisoryContext);
            $sourceId = $this->getSourceId($advisory['source'] ?? null, "{$advisoryContext}.source");
            $url = $this->getOptionalString($advisory, 'url', $advisoryContext);

            $findings[] = new AuditFinding(
                $this->sanitizeString($package),
                $this->getSeverity($advisory['severity'] ?? null, $advisoryContext),
                $this->getAdvisoryId($sourceId, null, $url),
                $sourceId,
                $this->getOptionalString($advisory, 'title', $advisoryContext) ?? 'Vulnerability found',
                $this->getString($advisory, 'range', $advisoryContext),
                $url,
                dependencyPaths: $paths,
            );
        }

        return $findings;
    }

    /**
     * @return list<AuditFinding>
     */
    private function parseVulnerability(int|string $package, mixed $vulnerability): array
    {
        if ('' === $package) {
            throw $this->malformed('each vulnerability must be keyed by a package name');
        }

        $package = (string) $package;

        $context = sprintf('vulnerabilities.%s', $package);

        $vulnerability = $this->getObject($vulnerability, $context);
        $name = $this->getString($vulnerability, 'name', $context);

        if ($package !== $name) {
            throw $this->malformed(sprintf('%s.name must match its vulnerability key', $context));
        }

        $this->getSeverity($vulnerability['severity'] ?? null, $context);
        $this->getBoolean($vulnerability, 'isDirect', $context);

        $via = $vulnerability['via'] ?? null;

        if (!is_array($via) || !array_is_list($via) || [] === $via) {
            throw $this->malformed(sprintf('%s.via must be a non-empty list', $context));
        }

        $this->getStringList($vulnerability['effects'] ?? null, $context . '.effects');
        $this->getString($vulnerability, 'range', $context);
        $paths = $this->getStringList($vulnerability['nodes'] ?? null, "{$context}.nodes");

        $fixAvailable = $vulnerability['fixAvailable'] ?? null;

        if (!is_bool($fixAvailable) && !$fixAvailable instanceof stdClass) {
            throw $this->malformed(sprintf('%s.fixAvailable must be a boolean or object', $context));
        }

        return $this->parseViaAdvisories($via, $package, $paths, $context);
    }

    /**
     * @param array<mixed> $metadata
     */
    private function validateMetadata(array $metadata, int $vulnerabilityCount): void
    {
        $severityTotal = $this->getSeverityCount($metadata, 'metadata', true);
        $dependencies = $this->getObject($metadata['dependencies'] ?? null, 'metadata.dependencies');

        foreach (['prod', 'dev', 'optional', 'peer', 'peerOptional', 'total'] as $dependencyType) {
            $this->getNonNegativeInteger($dependencies, $dependencyType, 'metadata.dependencies');
        }

        if ($severityTotal !== $vulnerabilityCount) {
            throw $this->malformed('metadata vulnerability counts must equal the vulnerability entries');
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function validateReportHeader(array $data): void
    {
        if (isset($data['error'])) {
            throw $this->malformed('the manager returned an error document');
        }

        if (2 !== ($data['auditReportVersion'] ?? null)) {
            throw $this->malformed('auditReportVersion must be 2');
        }
    }
}
