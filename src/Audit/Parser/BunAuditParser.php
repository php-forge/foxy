<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface};

use function sprintf;

final class BunAuditParser extends AbstractAuditParser implements AuditParserInterface
{
    public function parse(string $output): array
    {
        $packages = $this->decodeObject($output);

        $findings = [];

        foreach ($packages as $package => $advisories) {
            if (
                (string) $package === ''
                || !is_array($advisories)
                || !array_is_list($advisories)
            ) {
                throw $this->malformed('each package must contain a list of advisories');
            }

            $package = (string) $package;

            foreach ($advisories as $index => $advisory) {
                $context = sprintf('%s.%d', $package, $index);
                $advisory = $this->getObject($advisory, $context);
                $sourceId = $this->getSourceId($advisory['id'] ?? null, $context . '.id');
                $url = $this->getOptionalString($advisory, 'url', $context);

                $findings[] = new AuditFinding(
                    $this->sanitizeString($package),
                    $this->getSeverity($advisory['severity'] ?? null, $context),
                    $this->getAdvisoryId($sourceId, null, $url),
                    $sourceId,
                    $this->getOptionalString($advisory, 'title', $context) ?? 'Vulnerability found',
                    $this->getString($advisory, 'vulnerable_versions', $context),
                    $url,
                );
            }
        }

        return $findings;
    }

    protected function getManagerName(): string
    {
        return 'bun';
    }
}
