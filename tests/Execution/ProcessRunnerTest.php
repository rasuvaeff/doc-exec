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
        $outcome = (new ProcessRunner())->run(
            "<?php\nfwrite(STDOUT, 'out');\nfwrite(STDERR, 'err');\nexit(4);\n",
        );

        Assert::same($outcome->stdout, 'out');
        Assert::same($outcome->stderr, 'err');
        Assert::same($outcome->exitCode, 4);
        Assert::false($outcome->timedOut);
    }

    public function readsResultRowsFromTheDedicatedDescriptor(): void
    {
        $outcome = (new ProcessRunner())->run($this->scriptReporting("[['status' => 'pass'], ['status' => 'skip']]"));

        Assert::same($outcome->results, [['status' => 'pass'], ['status' => 'skip']]);
    }

    public function keepsResultsSeparateFromWhatTheDocumentPrints(): void
    {
        $source = "<?php\necho 'printed by the doc';\n"
            . "\$fp = fopen('php://fd/3', 'w');\nfwrite(\$fp, json_encode([['status' => 'pass']]));\nfclose(\$fp);\n";

        $outcome = (new ProcessRunner())->run($source);

        Assert::same($outcome->stdout, 'printed by the doc');
        Assert::same($outcome->results, [['status' => 'pass']]);
    }

    public function aJsonObjectOnTheResultsChannelIsNotAcceptedAsResults(): void
    {
        // Slots are numbered 0..n-1, so results are a list. An object would
        // silently mis-align every slot against its statement.
        $outcome = (new ProcessRunner())->run($this->scriptReporting("['x' => ['status' => 'pass']]"));

        Assert::null($outcome->results);
    }

    public function truncatedJsonOnTheResultsChannelIsNotAcceptedAsResults(): void
    {
        $source = "<?php\n\$fp = fopen('php://fd/3', 'w');\nfwrite(\$fp, '[{\"status\":');\nfclose(\$fp);\n";

        Assert::null((new ProcessRunner())->run($source)->results);
    }

    public function anEmptyResultsChannelYieldsNoResults(): void
    {
        Assert::null((new ProcessRunner())->run("<?php\n")->results);
    }

    public function aScalarOnTheResultsChannelIsNotAcceptedAsResults(): void
    {
        Assert::null((new ProcessRunner())->run($this->scriptReporting('42'))->results);
    }

    public function aRowThatIsNotAnArrayInvalidatesTheWholeBatch(): void
    {
        Assert::null((new ProcessRunner())->run($this->scriptReporting("[['status' => 'pass'], 'oops']"))->results);
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

    public function noTemporaryScriptSurvivesTheRun(): void
    {
        $before = glob(sys_get_temp_dir() . '/doc-exec-*');

        (new ProcessRunner())->run("<?php\necho 1;\n");

        Assert::same(glob(sys_get_temp_dir() . '/doc-exec-*'), $before);
    }

    public function anUnusablePhpBinaryIsReportedRatherThanSilentlyPassing(): void
    {
        Expect::exception(RuntimeException::class)->withMessageContaining('Unable to spawn');

        (new ProcessRunner(phpBinary: '/nonexistent/php', timeoutSeconds: 5))->run("<?php\n");
    }

    private function scriptReporting(string $phpArrayLiteral): string
    {
        return "<?php\n\$fp = fopen('php://fd/3', 'w');\n"
            . "fwrite(\$fp, json_encode({$phpArrayLiteral}));\nfclose(\$fp);\n";
    }
}
