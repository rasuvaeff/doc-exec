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
 * @internal
 */
final readonly class ProcessRunner
{
    public function __construct(
        private string $phpBinary = \PHP_BINARY,
    ) {}

    public function run(string $source): ProcessOutcome
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'doc-exec-');

        if ($scriptFile === false) {
            throw new RuntimeException('Unable to create a temporary script file');
        }

        file_put_contents($scriptFile, $source);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'w'],
        ];

        $process = proc_open([$this->phpBinary, $scriptFile], $descriptors, $pipes);

        if (!\is_resource($process)) {
            unlink($scriptFile);

            throw new RuntimeException('Unable to spawn a PHP process for doc-exec execution');
        }

        /** @var array<int, resource> $pipes */
        fclose($pipes[0]);

        [$buffers, $exitCode] = $this->drain($process, $pipes);

        unlink($scriptFile);

        $results = null;
        $resultsRaw = $buffers[3];

        if ($resultsRaw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($resultsRaw, true);

            if (\is_array($decoded)) {
                /** @var array<int|string, array<string, mixed>> $decoded */
                $results = $decoded;
            }
        }

        return new ProcessOutcome(
            exitCode: $exitCode,
            stdout: $buffers[1],
            stderr: $buffers[2],
            results: $results,
        );
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{0: array<int, string>, 1: int}
     */
    private function drain($process, array $pipes): array
    {
        /** @var array<int, resource> $open */
        $open = [1 => $pipes[1], 2 => $pipes[2], 3 => $pipes[3]];
        $buffers = [1 => '', 2 => '', 3 => ''];

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            if ($changed === false) {
                break;
            }

            foreach ($open as $fd => $pipe) {
                if (!\in_array($pipe, $read, true)) {
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

        $exitCode = proc_close($process);

        return [$buffers, $exitCode];
    }
}
