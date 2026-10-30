<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditNormalizer, Severity};
use Foxy\Exception\{Message, RuntimeException};
use JsonException;
use stdClass;
use Throwable;

use function array_map;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function preg_replace;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Provides strict validation and normalization helpers for audit output parsers.
 */
abstract class AbstractAuditParser
{
    private const int MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    /**
     * Returns the package manager name used in malformed-output errors.
     */
    abstract protected function getManagerName(): string;

    /**
     * Rejects a JSON document that represents a package manager error.
     *
     * @param array<mixed> $data
     */
    final protected function assertNotErrorDocument(array $data): void
    {
        if (isset($data['error'])) {
            throw $this->malformed(
                Message::AUDIT_REPORT_ERROR_DOCUMENT->getMessage(),
            );
        }
    }

    /**
     * Rejects audit output that exceeds the parser's size limit.
     */
    final protected function assertOutputSize(string $output): void
    {
        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            throw $this->malformed(
                Message::AUDIT_REPORT_SIZE_LIMIT_EXCEEDED->getMessage(),
            );
        }
    }

    /**
     * Decodes an audit JSON object after validating its size and shape.
     *
     * @return array<mixed>
     */
    final protected function decodeObject(string $output): array
    {
        $this->assertOutputSize($output);
        $output = trim($output);

        if ($output === '' || $output[0] !== '{') {
            throw $this->malformed(
                Message::AUDIT_REPORT_JSON_OBJECT_EXPECTED->getMessage(),
            );
        }

        try {
            $data = json_decode($output, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->malformed(
                Message::AUDIT_REPORT_JSON_INVALID->getMessage(),
                $exception,
            );
        }

        return get_object_vars($data);
    }

    /**
     * Selects a GHSA identifier from the candidate or URL, falling back to the source identifier.
     */
    final protected function getAdvisoryId(string $sourceId, string|null $candidate, string|null $url): string
    {
        return $this->getGhsaId($candidate) ?? $this->getGhsaId($url) ?? $sourceId;
    }

    /**
     * Returns a required boolean field.
     */
    final protected function getBoolean(array $data, string $key, string $context): bool
    {
        $value = $data[$key] ?? null;

        if (!is_bool($value)) {
            throw $this->malformed(
                Message::AUDIT_FIELD_BOOLEAN_REQUIRED->getMessage($context, $key),
            );
        }

        return $value;
    }

    /**
     * Returns validated, normalized CVE identifiers.
     *
     * @return list<string>
     */
    final protected function getCves(mixed $value, string $context): array
    {
        if (null === $value) {
            return [];
        }

        $cves = $this->getStringList($value, $context);

        foreach ($cves as $cve) {
            if (!AuditNormalizer::isCveId($cve)) {
                throw $this->malformed(
                    Message::AUDIT_VALUE_CVE_INVALID->getMessage($context),
                );
            }
        }

        return $this->uniqueStrings(array_map(strtoupper(...), $cves));
    }

    /**
     * Extracts and normalizes a GHSA identifier from a value or URL.
     */
    final protected function getGhsaId(string|null $value): string|null
    {
        return null === $value ? null : AuditNormalizer::normalizeGhsaReference($value);
    }

    /**
     * Returns a required non-negative integer field.
     */
    final protected function getNonNegativeInteger(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value) || $value < 0) {
            throw $this->malformed(
                Message::AUDIT_FIELD_NON_NEGATIVE_INTEGER_REQUIRED->getMessage($context, $key),
            );
        }

        return $value;
    }

    /**
     * Converts a decoded JSON object into its property map.
     *
     * @return array<mixed>
     */
    final protected function getObject(mixed $value, string $context): array
    {
        if (!$value instanceof stdClass) {
            throw $this->malformed(
                Message::AUDIT_VALUE_OBJECT_REQUIRED->getMessage($context),
            );
        }

        return get_object_vars($value);
    }

    /**
     * Returns a sanitized optional string field, or `null` when absent or empty.
     */
    final protected function getOptionalString(array $data, string $key, string $context): string|null
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = $data[$key];

        if (!is_string($value)) {
            throw $this->malformed(
                Message::AUDIT_FIELD_STRING_REQUIRED->getMessage($context, $key),
            );
        }

        return trim($value) === '' ? null : $this->sanitizeString($value);
    }

    /**
     * Returns a supported normalized severity value.
     */
    final protected function getSeverity(mixed $value, string $context): Severity
    {
        if (!is_string($value) || null === $severity = Severity::tryFrom(strtolower($value))) {
            throw $this->malformed(
                Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED->getMessage($context),
            );
        }

        return $severity;
    }

    /**
     * Returns the sum of severity counts and optionally validates the reported total.
     */
    final protected function getSeverityCount(array $metadata, string $context, bool $requireTotal): int
    {
        $counts = $this->getObject($metadata['vulnerabilities'] ?? null, $context . '.vulnerabilities');
        $total = 0;

        foreach (['info', 'low', 'moderate', 'high', 'critical'] as $severity) {
            $total += $this->getNonNegativeInteger($counts, $severity, $context . '.vulnerabilities');
        }

        if ($requireTotal && $this->getNonNegativeInteger($counts, 'total', $context . '.vulnerabilities') !== $total) {
            throw $this->malformed(
                Message::AUDIT_SEVERITY_TOTAL_MISMATCH->getMessage($context),
            );
        }

        return $total;
    }

    /**
     * Returns a non-empty source identifier from a string or integer value.
     */
    final protected function getSourceId(mixed $value, string $context): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw $this->malformed(
                Message::AUDIT_VALUE_STRING_OR_INTEGER_REQUIRED->getMessage($context),
            );
        }

        $sourceId = $this->sanitizeString((string) $value);

        if ($sourceId === '') {
            throw $this->malformed(
                Message::AUDIT_VALUE_EMPTY->getMessage($context),
            );
        }

        return $sourceId;
    }

    /**
     * Returns a required sanitized string field.
     */
    final protected function getString(array $data, string $key, string $context, bool $allowEmpty = false): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || (!$allowEmpty && trim($value) === '')) {
            throw $this->malformed(
                Message::AUDIT_FIELD_STRING_REQUIRED->getMessage($context, $key),
            );
        }

        return $this->sanitizeString($value);
    }

    /**
     * Returns a normalized list containing only strings.
     *
     * @return list<string>
     */
    final protected function getStringList(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw $this->malformed(
                Message::AUDIT_VALUE_LIST_REQUIRED->getMessage($context),
            );
        }

        foreach ($value as $index => $item) {
            if (!is_string($item)) {
                throw $this->malformed(
                    Message::AUDIT_VALUE_STRINGS_ONLY->getMessage($context),
                );
            }

            $value[$index] = $this->sanitizeString($item);
        }

        /** @var list<string> $value */
        return $this->uniqueStrings($value);
    }

    /**
     * Creates a package-manager-specific malformed-output exception.
     */
    final protected function malformed(string $reason, Throwable|null $previous = null): RuntimeException
    {
        return new RuntimeException(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage($this->getManagerName(), $reason),
            previous: $previous,
        );
    }

    /**
     * Replaces control characters and trims surrounding whitespace.
     */
    final protected function sanitizeString(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /**
     * Returns unique strings in deterministic order.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    final protected function uniqueStrings(array $values): array
    {
        return AuditNormalizer::uniqueSorted($values);
    }
}
