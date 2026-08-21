<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

/**
 * What one child process produced: its exit code, both output streams, the
 * per-slot rows it reported over fd 3 (null when it never reported usable
 * ones), and whether it was killed for exceeding its wall-clock budget.
 *
 * @internal
 */
final readonly class ProcessOutcome
{
    /**
     * @param list<array<string, mixed>>|null $results
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?array $results,
        public bool $timedOut = false,
    ) {}
}
