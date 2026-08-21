<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

use RuntimeException;

/**
 * Runs a generated script in its own `php` child process — never in the
 * process running doc-exec itself, and never through `eval()`.
 *
 * Every stream is a file, not a pipe: the child's stdout and stderr are
 * redirected to temporary files, and its per-statement outcomes go to a third
 * one whose path arrives as `argv[1]`. Pipes would buy nothing here — the
 * output is only read once the run is over — while costing the two failure
 * modes this class exists to avoid: `stream_select()` does not report
 * readiness for pipes on Windows, and a full pipe buffer deadlocks a child
 * whose other stream the parent has not drained yet.
 *
 * The child is bounded by a wall-clock deadline: a documentation block that
 * loops forever or blocks on a read is killed and reported, because a CI gate
 * that wedges is worse than one that fails.
 *
 * @psalm-import-type ResultRow from \Rasuvaeff\DocExec\Execution\ProcessOutcome
 *
 * @internal
 */
final readonly class ProcessRunner
{
    private const int POLL_MICROSECONDS = 2_000;

    /**
     * @param positive-int $timeoutSeconds wall-clock budget for one scope group
     */
    public function __construct(
        private string $phpBinary = \PHP_BINARY,
        private int $timeoutSeconds = 30,
    ) {}

    public function run(string $source): ProcessOutcome
    {
        $files = $this->createTemporaryFiles();

        try {
            return $this->runScript($files, $source);
        } finally {
            // Even on an exception these hold the document's own code and its
            // outcomes: leaving them behind is both litter and a leak.
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    /**
     * @return array{script: string, stdin: string, stdout: string, stderr: string, results: string}
     */
    private function createTemporaryFiles(): array
    {
        $files = [];

        foreach (['script', 'stdin', 'stdout', 'stderr', 'results'] as $role) {
            $file = tempnam(sys_get_temp_dir(), 'doc-exec-');

            if ($file === false) {
                foreach ($files as $created) {
                    unlink($created);
                }

                throw new RuntimeException('Unable to create a temporary file for doc-exec execution');
            }

            $files[$role] = $file;
        }

        /** @var array{script: string, stdin: string, stdout: string, stderr: string, results: string} $files */
        return $files;
    }

    /**
     * @param array{script: string, stdin: string, stdout: string, stderr: string, results: string} $files
     */
    private function runScript(array $files, string $source): ProcessOutcome
    {
        $written = file_put_contents($files['script'], $source);

        if ($written !== \strlen($source)) {
            throw new RuntimeException(\sprintf('Unable to write the generated script to "%s"', $files['script']));
        }

        // stdin is an empty file rather than a pipe or an inherited handle, so
        // a block that reads from it sees EOF instead of blocking forever.
        $descriptors = [
            0 => ['file', $files['stdin'], 'r'],
            1 => ['file', $files['stdout'], 'w'],
            2 => ['file', $files['stderr'], 'w'],
        ];

        $process = @proc_open([$this->phpBinary, $files['script'], $files['results']], $descriptors, $pipes);

        if (!\is_resource($process)) {
            throw new RuntimeException('Unable to spawn a PHP process for doc-exec execution');
        }

        [$exitCode, $timedOut] = $this->await($process);

        return new ProcessOutcome(
            exitCode: $exitCode,
            stdout: (string) file_get_contents($files['stdout']),
            stderr: (string) file_get_contents($files['stderr']),
            results: $this->decodeResults((string) file_get_contents($files['results'])),
            timedOut: $timedOut,
        );
    }

    /**
     * @param resource $process
     * @return array{0: int, 1: bool} [exit code, timed out]
     */
    private function await($process): array
    {
        $deadline = hrtime(as_number: true) + $this->timeoutSeconds * 1_000_000_000;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                proc_close($process);

                return [$status['exitcode'], $timedOut];
            }

            if (!$timedOut && hrtime(as_number: true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The results file is written by generated code as a JSON array of
     * per-slot rows. Anything else — a JSON object, a scalar, an empty file
     * left by a killed child — is treated as no results at all, which reports
     * the run as a process failure with its diagnostic.
     *
     * Rows are child-process output, so they are narrowed here rather than
     * asserted: fields that are not strings are dropped, and the rest of the
     * package can then read the shape without re-checking it.
     *
     * @return list<ResultRow>|null
     */
    private function decodeResults(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);

        if (!\is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                return null;
            }

            /** @var array<array-key, mixed> $row */
            $rows[] = $this->normalizeRow($row);
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return ResultRow
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach (['status', 'note', 'exception', 'actual', 'expected', 'output'] as $key) {
            if (isset($row[$key]) && \is_string($row[$key])) {
                $normalized[$key] = $row[$key];
            }
        }

        return $normalized;
    }
}
