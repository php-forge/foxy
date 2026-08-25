<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditFinding, AuditParserInterface};
use JsonException;
use stdClass;

use function explode;
use function get_object_vars;
use function json_decode;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

final class YarnAuditParser extends AbstractAuditParser implements AuditParserInterface
{
    public function parse(string $output): array
    {
        $this->assertOutputSize($output);
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        $findings = [];

        foreach (explode("\n", $output) as $lineNumber => $line) {
            try {
                $data = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw $this->malformed(sprintf('line %d contains invalid JSON', $lineNumber + 1), $exception);
            }

            if (!$data instanceof stdClass) {
                throw $this->malformed(sprintf('line %d is not an audit finding', $lineNumber + 1));
            }

            $data = get_object_vars($data);
            $context = sprintf('line %d.children', $lineNumber + 1);

            $children = $this->getObject($data['children'] ?? null, $context);
            $package = $this->getString($data, 'value', sprintf('line %d', $lineNumber + 1));
            $sourceId = $this->getSourceId($children['ID'] ?? null, $context . '.ID');
            $url = $this->getOptionalString($children, 'URL', $context);

            $findings[] = new AuditFinding(
                $package,
                $this->getSeverity($children['Severity'] ?? null, $context),
                $this->getAdvisoryId($sourceId, null, $url),
                $sourceId,
                $this->getString($children, 'Issue', $context),
                $this->getString($children, 'Vulnerable Versions', $context),
                $url,
                affectedVersions: $this->getStringList($children['Tree Versions'] ?? null, $context . '.Tree Versions'),
                dependencyPaths: $this->getStringList($children['Dependents'] ?? null, $context . '.Dependents'),
            );
        }

        return $findings;
    }

    protected function getManagerName(): string
    {
        return 'yarn';
    }
}
