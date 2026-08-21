<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

use RuntimeException;

/**
 * Runs a generated script in its own `php` child process — never in the
 * process running doc-exec itself, and never through `eval()`. Per-statement
 * outcomes travel back over a dedicated pipe (file descriptor 3), keeping
 * them separate from anything the doc's own code writes to real stdout.
 *
 * The child is bounded by a wall-clock deadline: a documentation block that
 * loops forever or blocks on a read is killed and reported, because a CI
 * gate that wedges is worse than one that fails.
 *
 * @psalm-import-type ResultRow from \Rasuvaeff\DocExec\Execution\ProcessOutcome
 *
 * @internal
 */
final readonly class ProcessRunner
{
    private const int POLL_MICROSECONDS = 200_000;

    /**
     * @param positive-int $timeoutSeconds wall-clock budget for one scope group
     */
    public function __construct(
        private string $phpBinary = \PHP_BINARY,
        private int $timeoutSeconds = 30,
    ) {}

    public function run(string $source): ProcessOutcome
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'doc-exec-');

        if ($scriptFile === false) {
            throw new RuntimeException('Unable to create a temporary script file');
        }

        try {
            return $this->runScriptFile($scriptFile, $source);
        } finally {
            // Even on an exception the file holds the document's own code:
            // leaving it in the temp directory is both litter and a leak.
            unlink($scriptFile);
        }
    }

    private function runScriptFile(string $scriptFile, string $source): ProcessOutcome
    {
        $written = file_put_contents($scriptFile, $source);

        if ($written !== \strlen($source)) {
            throw new RuntimeException(\sprintf('Unable to write the generated script to "%s"', $scriptFile));
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'w'],
        ];

        $process = @proc_open([$this->phpBinary, $scriptFile], $descriptors, $pipes);

        if (!\is_resource($process)) {
            throw new RuntimeException('Unable to spawn a PHP process for doc-exec execution');
        }

        /** @var array<int, resource> $pipes */
        fclose($pipes[0]);

        [$buffers, $exitCode, $timedOut] = $this->drain($process, $pipes);

        return new ProcessOutcome(
            exitCode: $exitCode,
            stdout: $buffers[1],
            stderr: $buffers[2],
            results: $this->decodeResults($buffers[3]),
            timedOut: $timedOut,
        );
    }

    /**
     * The results channel is written by generated code as a JSON array of
     * per-slot rows. Anything else — a JSON object, a scalar, truncated
     * output from a killed child — is treated as no results at all, which
     * reports the run as a process failure with its diagnostic.
     *
     * Rows are child-process output, so they are narrowed here rather than
     * asserted: fields that are not strings are dropped, and the rest of
     * the package can then read the shape without re-checking it.
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

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{0: array<int, string>, 1: int, 2: bool} [buffers, exit code, timed out]
     */
    private function drain($process, array $pipes): array
    {
        /** @var array<int, resource> $open */
        $open = [1 => $pipes[1], 2 => $pipes[2], 3 => $pipes[3]];
        $buffers = [1 => '', 2 => '', 3 => ''];
        $deadline = hrtime(as_number: true) + $this->timeoutSeconds * 1_000_000_000;
        $timedOut = false;

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, enable: false);
        }

        while ($open !== []) {
            if (hrtime(as_number: true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);

                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS);

            if ($changed === false) {
                break;
            }

            foreach ($open as $fd => $pipe) {
                if (!\in_array($pipe, $read, strict: true)) {
                    continue;
                }

                $chunk = fread($pipe, 65536);

                if ($chunk === '' || $chunk === false) {
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($open[$fd]);
                    }

                    continue;
                }

                $buffers[$fd] .= $chunk;
            }
        }

        foreach ($open as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);

        return [$buffers, $exitCode, $timedOut];
    }
}
