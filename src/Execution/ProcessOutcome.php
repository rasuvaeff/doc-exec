<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

/**
 * @internal
 */
final readonly class ProcessOutcome
{
    /**
     * @param array<int|string, array<string, mixed>>|null $results
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?array $results,
    ) {}
}
