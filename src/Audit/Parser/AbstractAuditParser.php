<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\Severity;
use Foxy\Exception\RuntimeException;
use JsonException;
use stdClass;

use function array_map;
use function array_unique;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

use const JSON_THROW_ON_ERROR;

abstract class AbstractAuditParser
{
    private const int MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    abstract protected function getManagerName(): string;

    final protected function assertOutputSize(string $output): void
    {
        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            throw $this->malformed('the report exceeds the 16 MiB safety limit');
        }
    }

    /**
     * @return array<mixed>
     */
    final protected function decodeObject(string $output): array
    {
        $this->assertOutputSize($output);
        $output = trim($output);

        if ($output === '' || $output[0] !== '{') {
            throw $this->malformed('expected a JSON object');
        }

        try {
            $data = json_decode($output, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->malformed('invalid JSON', $exception);
        }

        return get_object_vars($data);
    }

    final protected function getAdvisoryId(string $sourceId, string|null $candidate, string|null $url): string
    {
        return $this->getGhsaId($candidate) ?? $this->getGhsaId($url) ?? $sourceId;
    }

    final protected function getBoolean(array $data, string $key, string $context): bool
    {
        $value = $data[$key] ?? null;

        if (!is_bool($value)) {
            throw $this->malformed(sprintf('%s.%s must be a boolean', $context, $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    final protected function getCves(mixed $value, string $context): array
    {
        if (null === $value) {
            return [];
        }

        $cves = $this->getStringList($value, $context);

        foreach ($cves as $cve) {
            if (1 !== preg_match('/^CVE-\d{4}-\d{4,}$/i', $cve)) {
                throw $this->malformed(sprintf('%s contains an invalid CVE identifier', $context));
            }
        }

        return $this->uniqueStrings(array_map(strtoupper(...), $cves));
    }

    final protected function getGhsaId(string|null $value): string|null
    {
        if (
            null === $value
            || 1 !== preg_match(
                '{^(?:https://github\.com/advisories/)?GHSA-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})/?$}i',
                $value,
                $matches,
            )
        ) {
            return null;
        }

        return 'GHSA-' . strtolower($matches[1] . '-' . $matches[2] . '-' . $matches[3]);
    }

    final protected function getNonNegativeInteger(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value) || $value < 0) {
            throw $this->malformed(sprintf('%s.%s must be a non-negative integer', $context, $key));
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    final protected function getObject(mixed $value, string $context): array
    {
        if (!$value instanceof stdClass) {
            throw $this->malformed(sprintf('%s must be an object', $context));
        }

        return get_object_vars($value);
    }

    final protected function getOptionalString(array $data, string $key, string $context): string|null
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = $data[$key];

        if (!is_string($value)) {
            throw $this->malformed(sprintf('%s.%s must be a string', $context, $key));
        }

        return trim($value) === '' ? null : $this->sanitizeString($value);
    }

    final protected function getSeverity(mixed $value, string $context): Severity
    {
        if (!is_string($value) || null === $severity = Severity::tryFrom(strtolower($value))) {
            throw $this->malformed(sprintf('%s has an unsupported severity', $context));
        }

        return $severity;
    }

    final protected function getSeverityCount(array $metadata, string $context, bool $requireTotal): int
    {
        $counts = $this->getObject($metadata['vulnerabilities'] ?? null, $context . '.vulnerabilities');
        $total = 0;

        foreach (['info', 'low', 'moderate', 'high', 'critical'] as $severity) {
            $total += $this->getNonNegativeInteger($counts, $severity, $context . '.vulnerabilities');
        }

        if ($requireTotal && $this->getNonNegativeInteger($counts, 'total', $context . '.vulnerabilities') !== $total) {
            throw $this->malformed(sprintf('%s.vulnerabilities.total must equal the severity counts', $context));
        }

        return $total;
    }

    final protected function getSourceId(mixed $value, string $context): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw $this->malformed(sprintf('%s must be a string or integer', $context));
        }

        $sourceId = $this->sanitizeString((string) $value);

        if ($sourceId === '') {
            throw $this->malformed(sprintf('%s must not be empty', $context));
        }

        return $sourceId;
    }

    final protected function getString(array $data, string $key, string $context, bool $allowEmpty = false): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || (!$allowEmpty && trim($value) === '')) {
            throw $this->malformed(sprintf('%s.%s must be a string', $context, $key));
        }

        return $this->sanitizeString($value);
    }

    /**
     * @return list<string>
     */
    final protected function getStringList(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw $this->malformed(sprintf('%s must be a list', $context));
        }

        foreach ($value as $index => $item) {
            if (!is_string($item)) {
                throw $this->malformed(sprintf('%s must contain only strings', $context));
            }

            $value[$index] = $this->sanitizeString($item);
        }

        /** @var list<string> $value */
        return $this->uniqueStrings($value);
    }

    final protected function malformed(string $reason, \Throwable|null $previous = null): RuntimeException
    {
        return new RuntimeException(
            sprintf('The %s audit output is malformed: %s.', $this->getManagerName(), $reason),
            previous: $previous,
        );
    }

    final protected function sanitizeString(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    final protected function uniqueStrings(array $values): array
    {
        $values = array_unique($values);
        sort($values);

        return $values;
    }
}
