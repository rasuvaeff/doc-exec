<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Execution;

use Rasuvaeff\DocExec\Execution\ProcessRunner;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProcessRunner::class)]
final class ProcessRunnerTest
{
    public function reportsStdoutStderrAndExitCode(): void
    {
        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run(
            "<?php\nfwrite(STDOUT, 'out');\nfwrite(STDERR, 'err');\nexit(4);\n",
        );

        Assert::same($outcome->stdout, 'out');
        Assert::same($outcome->stderr, 'err');
        Assert::same($outcome->exitCode, 4);
        Assert::false($outcome->timedOut);
    }

    public function readsResultRowsFromTheResultsFile(): void
    {
        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run($this->scriptReporting("[['status' => 'pass'], ['status' => 'skip']]"));

        Assert::same($outcome->results, [['status' => 'pass'], ['status' => 'skip']]);
    }

    public function theChildIsHandedAWritableResultsPathAsItsFirstArgument(): void
    {
        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run(
            "<?php\nfile_put_contents(\$argv[1], json_encode([['status' => 'pass', 'note' => \$argv[1]]]));\n",
        );

        $path = (string) ($outcome->results[0]['note'] ?? '');

        Assert::same(\count((array) $outcome->results), 1);
        // Not a check on the file's name: Windows' tempnam() keeps only the
        // first three characters of a prefix, so asserting on "doc-exec-"
        // would fail there for a path that is perfectly correct.
        Assert::same(\dirname($path), realpath(sys_get_temp_dir()));
        Assert::true(is_writable(\dirname($path)));
    }

    public function keepsResultsSeparateFromWhatTheDocumentPrints(): void
    {
        $source = "<?php\necho 'printed by the doc';\n"
            . "file_put_contents(\$argv[1], json_encode([['status' => 'pass']]));\n";

        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run($source);

        Assert::same($outcome->stdout, 'printed by the doc');
        Assert::same($outcome->results, [['status' => 'pass']]);
    }

    public function aJsonObjectOnTheResultsChannelIsNotAcceptedAsResults(): void
    {
        // Slots are numbered 0..n-1, so results are a list. An object would
        // silently mis-align every slot against its statement.
        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run($this->scriptReporting("['x' => ['status' => 'pass']]"));

        Assert::null($outcome->results);
    }

    public function truncatedJsonOnTheResultsChannelIsNotAcceptedAsResults(): void
    {
        $source = "<?php\nfile_put_contents(\$argv[1], '[{\"status\":');\n";

        Assert::null((new ProcessRunner(timeoutSeconds: 5))->run($source)->results);
    }

    public function anEmptyResultsChannelYieldsNoResults(): void
    {
        Assert::null((new ProcessRunner(timeoutSeconds: 5))->run("<?php\n")->results);
    }

    public function aScalarOnTheResultsChannelIsNotAcceptedAsResults(): void
    {
        Assert::null((new ProcessRunner(timeoutSeconds: 5))->run($this->scriptReporting('42'))->results);
    }

    public function aRowThatIsNotAnArrayInvalidatesTheWholeBatch(): void
    {
        Assert::null((new ProcessRunner(timeoutSeconds: 5))->run($this->scriptReporting("[['status' => 'pass'], 'oops']"))->results);
    }

    public function aBlockThatNeverFinishesIsKilledAndFlagged(): void
    {
        // The whole point of the deadline: without it this call never returns
        // and the CI job hangs instead of failing.
        $outcome = (new ProcessRunner(timeoutSeconds: 1))->run("<?php\nwhile (true) {}\n");

        Assert::true($outcome->timedOut);
        Assert::null($outcome->results);
    }

    public function outputProducedBeforeATimeoutIsStillReported(): void
    {
        $outcome = (new ProcessRunner(timeoutSeconds: 1))->run(
            "<?php\nfwrite(STDOUT, 'partial');\nfflush(STDOUT);\nwhile (true) {}\n",
        );

        Assert::true($outcome->timedOut);
        Assert::string($outcome->stdout)->contains('partial');
    }

    public function largeChildOutputDoesNotDeadlockThePipes(): void
    {
        // A single OS pipe buffer is ~64 KB: a blocking read of one stream
        // while the child fills another is the classic proc_open deadlock.
        $source = "<?php\necho str_repeat('x', 300000);\nfwrite(STDERR, str_repeat('e', 300000));\n";

        $outcome = (new ProcessRunner(timeoutSeconds: 10))->run($source);

        Assert::same(\strlen($outcome->stdout), 300000);
        Assert::same(\strlen($outcome->stderr), 300000);
        Assert::false($outcome->timedOut);
    }

    public function noTemporaryFileSurvivesTheRun(): void
    {
        // The paths this run used are reported by the run itself, so the
        // check names exact files instead of diffing a shared directory that
        // any other process — or a parallel test — may also be writing to.
        $paths = $this->pathsUsedByARun();

        Assert::true($paths !== []);

        foreach ($paths as $path) {
            Assert::false(file_exists($path));
        }
    }

    public function theResultsFileIsRemovedEvenWhenTheChildIsKilled(): void
    {
        $marker = sys_get_temp_dir() . '/doc-exec-killed-' . bin2hex(random_bytes(6));

        try {
            (new ProcessRunner(timeoutSeconds: 1))->run(
                "<?php\nfile_put_contents('" . $marker . "', \$argv[1]);\nwhile (true) {}\n",
            );

            $resultsFile = (string) file_get_contents($marker);

            Assert::true($resultsFile !== '');
            Assert::false(file_exists($resultsFile));
        } finally {
            @unlink($marker);
        }
    }

    /**
     * @return list<string>
     */
    private function pathsUsedByARun(): array
    {
        $source = "<?php\nfile_put_contents(\$argv[1], json_encode([['status' => 'pass', 'note' => \$argv[0] . '|' . \$argv[1]]]));\n";

        $note = (string) ((new ProcessRunner(timeoutSeconds: 5))->run($source)->results[0]['note'] ?? '');

        return $note === '' ? [] : explode('|', $note);
    }

    public function anUnusablePhpBinaryIsReportedRatherThanSilentlyPassing(): void
    {
        Expect::exception(RuntimeException::class)->withMessageContaining('Unable to spawn');

        (new ProcessRunner(phpBinary: '/nonexistent/php', timeoutSeconds: 5))->run("<?php\n");
    }

    public function theChildGetsAClosedStdinRatherThanBlockingOnIt(): void
    {
        // A block calling fgets(STDIN) must see EOF: an inherited or open
        // stdin would hang the run until the deadline killed it.
        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run(
            "<?php\n\$line = fgets(STDIN);\necho var_export(\$line, true);\n",
        );

        Assert::same($outcome->stdout, 'false');
        Assert::false($outcome->timedOut);
    }

    public function nonStringFieldsInAResultRowAreDroppedRatherThanTrusted(): void
    {
        // The rows come from a child process running the document's own code:
        // narrowing them here is what lets the rest of the package read the
        // shape without re-checking every field.
        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run(
            $this->scriptReporting("[['status' => 'pass', 'output' => 42, 'note' => null, 'extra' => 'x']]"),
        );

        Assert::same($outcome->results, [['status' => 'pass']]);
    }

    public function everyStreamIsDrainedNotJustTheFirstOneReady(): void
    {
        // Dropping either pipe from the loop loses the document's output or
        // its diagnostics.
        $source = "<?php\nfwrite(STDOUT, 'o');\nfwrite(STDERR, 'e');\n"
            . "file_put_contents(\$argv[1], json_encode([['status' => 'pass']]));\n";

        $outcome = (new ProcessRunner(timeoutSeconds: 5))->run($source);

        Assert::same($outcome->stdout, 'o');
        Assert::same($outcome->stderr, 'e');
        Assert::same($outcome->results, [['status' => 'pass']]);
    }

    public function outputArrivingInSeveralChunksIsConcatenatedInOrder(): void
    {
        $source = "<?php\nforeach (['a', 'b', 'c'] as \$part) {\n    echo \$part;\n    usleep(50000);\n}\n";

        Assert::same((new ProcessRunner(timeoutSeconds: 10))->run($source)->stdout, 'abc');
    }

    public function aProcessThatOutlivesItsBudgetIsKilledCloseToTheDeadline(): void
    {
        $started = hrtime(as_number: true);
        (new ProcessRunner(timeoutSeconds: 1))->run("<?php\nwhile (true) {}\n");
        $elapsedSeconds = (hrtime(as_number: true) - $started) / 1_000_000_000;

        Assert::true($elapsedSeconds >= 1.0);
        Assert::true($elapsedSeconds < 5.0);
    }

    public function aProcessFinishingWellInsideItsBudgetIsNotFlagged(): void
    {
        $outcome = (new ProcessRunner(timeoutSeconds: 30))->run("<?php\nusleep(100000);\necho 'done';\n");

        Assert::false($outcome->timedOut);
        Assert::same($outcome->stdout, 'done');
    }

    private function scriptReporting(string $phpArrayLiteral): string
    {
        return "<?php\nfile_put_contents(\$argv[1], json_encode({$phpArrayLiteral}));\n";
    }
}
