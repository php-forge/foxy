<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface};
use Foxy\Exception\Message;

use function is_array;
use function sprintf;

/**
 * Parses the npm registry's bulk advisories document, which the `native` manager fetches and `bun audit` prints.
 *
 * The document maps each package name to its advisories; it does not say which locked version matched, so the
 * findings carry no affected versions and no dependency paths.
 */
abstract class AbstractBulkAdvisoryParser extends AbstractAuditParser implements AuditParserInterface
{
    public function parse(string $output): array
    {
        $packages = $this->decodeObject($output);

        $findings = [];

        foreach ($packages as $package => $advisories) {
            if ($package === '' || !is_array($advisories)) {
                throw $this->malformed(
                    Message::AUDIT_BULK_PACKAGE_ADVISORIES_REQUIRED->getMessage(),
                );
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
}
