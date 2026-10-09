<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface};
use Foxy\Exception\Message;
use stdClass;

use function array_push;
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
            array_push($findings, ...$this->parseVulnerability($package, $vulnerability));
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
                throw $this->malformed(
                    Message::AUDIT_NPM_VIA_ENTRY_INVALID->getMessage($context, $index),
                );
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
            throw $this->malformed(
                Message::AUDIT_NPM_VULNERABILITY_KEY_REQUIRED->getMessage(),
            );
        }

        $package = (string) $package;

        $context = sprintf('vulnerabilities.%s', $package);

        $vulnerability = $this->getObject($vulnerability, $context);
        $name = $this->getString($vulnerability, 'name', $context);

        if ($package !== $name) {
            throw $this->malformed(
                Message::AUDIT_NPM_NAME_KEY_MISMATCH->getMessage($context),
            );
        }

        $this->getSeverity($vulnerability['severity'] ?? null, $context);
        $this->getBoolean($vulnerability, 'isDirect', $context);

        $via = $vulnerability['via'] ?? null;

        if (!is_array($via) || [] === $via) {
            throw $this->malformed(
                Message::AUDIT_NPM_VIA_LIST_EMPTY->getMessage($context),
            );
        }

        $this->getStringList($vulnerability['effects'] ?? null, $context . '.effects');
        $this->getString($vulnerability, 'range', $context);

        $paths = $this->getStringList($vulnerability['nodes'] ?? null, "{$context}.nodes");

        $fixAvailable = $vulnerability['fixAvailable'] ?? null;

        if (!is_bool($fixAvailable) && !$fixAvailable instanceof stdClass) {
            throw $this->malformed(
                Message::AUDIT_NPM_FIX_AVAILABLE_INVALID->getMessage($context),
            );
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
            throw $this->malformed(
                Message::AUDIT_NPM_METADATA_COUNT_MISMATCH->getMessage(),
            );
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function validateReportHeader(array $data): void
    {
        $this->assertNotErrorDocument($data);

        if (2 !== ($data['auditReportVersion'] ?? null)) {
            throw $this->malformed(
                Message::AUDIT_NPM_REPORT_VERSION_UNSUPPORTED->getMessage(),
            );
        }
    }
}
