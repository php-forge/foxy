<?php

declare(strict_types=1);

namespace Foxy\Audit\Parser;

use Foxy\Audit\{AuditNormalizer, Severity};
use Foxy\Exception\{Message, RuntimeException};
use JsonException;
use stdClass;

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

abstract class AbstractAuditParser
{
    private const int MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    abstract protected function getManagerName(): string;

    /**
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

    final protected function assertOutputSize(string $output): void
    {
        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            throw $this->malformed(
                Message::AUDIT_REPORT_SIZE_LIMIT_EXCEEDED->getMessage(),
            );
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

    final protected function getAdvisoryId(string $sourceId, string|null $candidate, string|null $url): string
    {
        return $this->getGhsaId($candidate) ?? $this->getGhsaId($url) ?? $sourceId;
    }

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

    final protected function getGhsaId(string|null $value): string|null
    {
        return null === $value ? null : AuditNormalizer::normalizeGhsaReference($value);
    }

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

    final protected function getSeverity(mixed $value, string $context): Severity
    {
        if (!is_string($value) || null === $severity = Severity::tryFrom(strtolower($value))) {
            throw $this->malformed(
                Message::AUDIT_VALUE_SEVERITY_UNSUPPORTED->getMessage($context),
            );
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
            throw $this->malformed(
                Message::AUDIT_SEVERITY_TOTAL_MISMATCH->getMessage($context),
            );
        }

        return $total;
    }

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

    final protected function malformed(string $reason, \Throwable|null $previous = null): RuntimeException
    {
        return new RuntimeException(
            Message::AUDIT_OUTPUT_MALFORMED->getMessage($this->getManagerName(), $reason),
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
        return AuditNormalizer::uniqueSorted($values);
    }
}
