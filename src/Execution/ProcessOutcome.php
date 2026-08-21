<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

/**
 * The one row shape the results file carries, normalised on arrival by
 * {@see ProcessRunner}: every value present is a string, so nothing
 * downstream has to re-check what the child process claimed.
 *
 * @psalm-type ResultRow = array{
 *     status?: string,
 *     note?: string,
 *     exception?: string,
 *     actual?: string,
 *     expected?: string,
 *     output?: string,
 * }
 *
 * What one child process produced: its exit code, both output streams, the
 * per-slot rows it reported (null when it never reported usable ones), and
 * whether it was killed for exceeding its wall-clock budget.
 *
 * @internal
 */
final readonly class ProcessOutcome
{
    /**
     * @param list<ResultRow>|null $results
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?array $results,
        public bool $timedOut = false,
    ) {}
}
