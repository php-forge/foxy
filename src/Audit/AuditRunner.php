<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Foxy\Asset\AssetManagerInterface;
use Foxy\Exception\RuntimeException;
use Throwable;

use function in_array;
use function sprintf;
use function trim;
use function usort;

final readonly class AuditRunner implements AuditRunnerInterface
{
    public function __construct(private AssetManagerInterface&AuditableAssetManagerInterface $manager) {}

    public function audit(AuditRequest $request): AuditReport
    {
        $manager = $this->manager->getName();
        $result = $this->manager->audit($request->noDev);

        $diagnostics = trim($result->errorOutput);

        if (!in_array($result->exitCode, [0, 1], true)) {
            throw $this->executionFailure($manager, $result);
        }

        if ('bun' === $manager && '' !== $diagnostics) {
            throw new RuntimeException(
                "The bun audit command produced diagnostics and may have returned a partial report. {$diagnostics}",
            );
        }

        try {
            $findings = AuditParserFactory::create($manager)->parse($result->output);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            if ($diagnostics !== '') {
                $message .= " Manager error: {$diagnostics}";
            }

            throw new RuntimeException($message, previous: $exception);
        }

        $findings = $this->normalize($findings);

        $report = new AuditReport($manager, $findings, $diagnostics);

        if (1 === $result->exitCode && [] === $findings) {
            throw $this->executionFailure($manager, $result);
        }

        if (0 === $result->exitCode && $report->hasFindingAtLeast(Severity::LOW)) {
            throw new RuntimeException(
                sprintf('The %s audit report contains vulnerabilities but the manager returned a successful status.', $manager),
            );
        }

        return $report;
    }

    private function executionFailure(string $manager, AuditProcessResult $result): RuntimeException
    {
        $message = sprintf('The %s audit command failed with status code %d.', $manager, $result->exitCode);
        $diagnostics = trim($result->errorOutput);

        if ($diagnostics !== '') {
            $message .= " {$diagnostics}";
        }

        return new RuntimeException($message, $result->exitCode);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return list<string>
     */
    private function mergeStrings(array $left, array $right): array
    {
        $values = [...$left, ...$right];

        $values = array_unique($values);
        sort($values);

        return $values;
    }

    /**
     * @param list<AuditFinding> $findings
     *
     * @return list<AuditFinding>
     */
    private function normalize(array $findings): array
    {
        $normalized = [];

        foreach ($findings as $finding) {
            $key = "{$finding->package}\0{$finding->advisoryId}\0{$finding->vulnerableVersions}";
            $existing = $normalized[$key] ?? null;

            if (!$existing instanceof AuditFinding) {
                $normalized[$key] = $finding;

                continue;
            }

            $severity = $finding->severity->isAtLeast($existing->severity)
                ? $finding->severity
                : $existing->severity;
            $cves = $this->mergeStrings($existing->cves, $finding->cves);

            $normalized[$key] = new AuditFinding(
                $existing->package,
                $severity,
                $existing->advisoryId,
                $existing->sourceId,
                $existing->title,
                $existing->vulnerableVersions,
                $existing->url ?? $finding->url,
                $cves,
                [] === $cves ? $existing->cveStatus : CveStatus::RESOLVED,
                $this->mergeStrings($existing->affectedVersions, $finding->affectedVersions),
                $this->mergeStrings($existing->dependencyPaths, $finding->dependencyPaths),
            );
        }

        usort(
            $normalized,
            static fn(AuditFinding $left, AuditFinding $right): int => [
                -$left->severity->weight(),
                $left->package,
                $left->advisoryId,
            ] <=> [
                -$right->severity->weight(),
                $right->package,
                $right->advisoryId,
            ],
        );

        return $normalized;
    }
}
