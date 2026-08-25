<?php

declare(strict_types=1);

namespace Foxy\Command;

use Composer\Command\BaseCommand;
use Foxy\Audit\{
    AuditFormat,
    AuditFormatter,
    AuditRequest,
    AuditRunnerInterface,
    CveEnricher,
    CveResolverInterface,
    GitHubAdvisoryCveResolver,
    Severity
};
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_string;
use function preg_replace;
use function trim;

final class AuditCommand extends BaseCommand
{
    public const int STATUS_FAILED = 2;
    public const int STATUS_OK = 0;
    public const int STATUS_VULNERABLE = 1;

    public function __construct(
        private readonly AuditRunnerInterface $runner,
        private readonly AuditFormatter $formatter = new AuditFormatter(),
        private readonly CveResolverInterface|null $cveResolver = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('foxy:audit')
            ->setDescription('Checks frontend dependencies for known security vulnerabilities')
            ->setDefinition([
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Excludes development dependencies.'),
                new InputOption(
                    'format',
                    'f',
                    InputOption::VALUE_REQUIRED,
                    'Output format: table, plain, json, or summary.',
                    AuditFormat::TABLE->value,
                ),
                new InputOption(
                    'audit-level',
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Minimum severity that makes the command fail: low, moderate, high, or critical.',
                    Severity::LOW->value,
                ),
                new InputOption(
                    'no-cve',
                    null,
                    InputOption::VALUE_NONE,
                    'Skips GitHub advisory lookups for CVE identifiers.',
                ),
            ])
            ->setHelp(
                <<<HELP
                The <info>foxy:audit</info> command audits the selected frontend manager's lock file.

                It reports all known advisories and uses <info>--audit-level</info> only to determine the exit status.
                Exit status 0 means no advisory met the threshold, 1 means at least one advisory met it, and 2 means the audit could not be completed reliably.
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatValue = $input->getOption('format');
        $severityValue = $input->getOption('audit-level');

        $format = is_string($formatValue) ? AuditFormat::tryFrom($formatValue) : null;
        $minimumSeverity = is_string($severityValue) ? Severity::tryFrom($severityValue) : null;

        if (null === $format) {
            $this->getIO()->writeError('<error>The audit output format must be table, plain, json, or summary.</error>');

            return self::STATUS_FAILED;
        }

        if (null === $minimumSeverity || Severity::INFO === $minimumSeverity) {
            $this->getIO()->writeError(
                '<error>The audit level must be low, moderate, high, or critical.</error>',
            );

            return self::STATUS_FAILED;
        }

        try {
            $report = $this->runner->audit(
                new AuditRequest($minimumSeverity, true === $input->getOption('no-dev')),
            );

            if ($report->diagnostics !== '') {
                $this->getIO()->writeError(OutputFormatter::escape($this->sanitize($report->diagnostics)));
            }

            if (true !== $input->getOption('no-cve') && AuditFormat::SUMMARY !== $format && [] !== $report->findings) {
                $resolver = $this->cveResolver ?? new GitHubAdvisoryCveResolver(
                    $this->requireComposer()->getLoop()->getHttpDownloader(),
                );
                $report = (new CveEnricher($resolver))->enrich(
                    $report,
                    function (string $warning): void {
                        $this->getIO()->writeError(
                            '<warning>' . OutputFormatter::escape($this->sanitize($warning)) . '</warning>',
                        );
                    },
                );
            }

            $this->formatter->write($report, $minimumSeverity, $format, $output);

            return $report->hasFindingAtLeast($minimumSeverity)
                ? self::STATUS_VULNERABLE
                : self::STATUS_OK;
        } catch (Throwable $exception) {
            $message = OutputFormatter::escape($this->sanitize($exception->getMessage()));

            $this->getIO()->writeError('<error>Foxy audit failed: ' . $message . '</error>');

            return self::STATUS_FAILED;
        }
    }

    private function sanitize(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }
}
