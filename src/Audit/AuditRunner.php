<?php

declare(strict_types=1);

namespace Foxy\Audit;

use Foxy\Asset\AssetManagerInterface;
use Foxy\Exception\{Message, RuntimeException};
use Throwable;

use function in_array;
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
            throw $this->executionFailure($manager, $result->exitCode, $diagnostics);
        }

        if ('bun' === $manager && '' !== $diagnostics) {
            throw new RuntimeException(
                Message::AUDIT_BUN_PARTIAL_REPORT->getMessage($diagnostics),
            );
        }

        try {
            $findings = AuditParserFactory::create($manager)->parse($result->output);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            if ($diagnostics !== '') {
                $message = Message::AUDIT_MANAGER_ERROR_APPENDED->getMessage($message, $diagnostics);
            }

            throw new RuntimeException(
                $message,
                previous: $exception,
            );
        }

        $findings = $this->normalize($findings);

        $report = new AuditReport($manager, $findings, $diagnostics);

        if (1 === $result->exitCode && [] === $findings) {
            throw $this->executionFailure($manager, $result->exitCode, $diagnostics);
        }

        if (0 === $result->exitCode && $report->hasFindingAtLeast(Severity::LOW)) {
            throw new RuntimeException(
                Message::AUDIT_SUCCESS_STATUS_WITH_FINDINGS->getMessage($manager),
            );
        }

        return $report;
    }

    private function executionFailure(string $manager, int $exitCode, string $diagnostics): RuntimeException
    {
        $message = '' === $diagnostics
            ? Message::AUDIT_COMMAND_FAILED->getMessage($manager, $exitCode)
            : Message::AUDIT_COMMAND_FAILED_WITH_DIAGNOSTICS->getMessage($manager, $exitCode, $diagnostics);

        return new RuntimeException($message, $exitCode);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return list<string>
     */
    private function mergeStrings(array $left, array $right): array
    {
        return AuditNormalizer::uniqueSorted([...$left, ...$right]);
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

            $selected = $finding->severity->isAtLeast($existing->severity) ? $finding : $existing;
            $cves = $this->mergeStrings($existing->cves, $finding->cves);

            $normalized[$key] = new AuditFinding(
                $selected->package,
                $selected->severity,
                $selected->advisoryId,
                $selected->sourceId,
                $selected->title,
                $selected->vulnerableVersions,
                $selected->url,
                $cves,
                [] === $cves ? $selected->cveStatus : CveStatus::RESOLVED,
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
