<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\DocExec;
use Rasuvaeff\DocExec\StableId;
use Rasuvaeff\DocExec\StatementOutcome;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DocExec::class)]
final class DocExecTest
{
    private const int CATALOG_SIZE = 20;

    private string $bootstrap;
    /** @var list<string> */
    private array $tempFiles = [];

    #[BeforeTest]
    public function setUp(): void
    {
        $this->bootstrap = \dirname(__DIR__) . '/vendor/autoload.php';
    }

    #[AfterTest]
    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }

    public function twoPlusThreeEqualsFiveIsGreen(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            2 + 3; // => 5
            ```
            MD);

        Assert::true($result->passed());
        Assert::same($result->failedIds(), []);
    }

    public function twoPlusThreeEqualsSixIsRed(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            2 + 3; // => 6
            ```
            MD);

        Assert::false($result->passed());
        Assert::same(\count($result->failedIds()), 1);
    }

    public function plainStatementWithoutMarkerJustMustNotThrow(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            $x = 1;
            $x + 1;
            ```
            MD);

        Assert::true($result->passed());
    }

    public function unexpectedThrowFailsTheBlock(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            throw new \RuntimeException('boom');
            ```
            MD);

        Assert::false($result->passed());
        Assert::same($result->blocks[0]->statements[0]->outcome, StatementOutcome::Fail);
    }

    public function throwsMarkerPassesOnMatchingException(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            intdiv(1, 0); // throws DivisionByZeroError
            ```
            MD);

        Assert::true($result->passed());
    }

    public function throwsMarkerFailsWhenNothingIsThrown(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            1 + 1; // throws \RuntimeException
            ```
            MD);

        Assert::false($result->passed());
    }

    public function throwsMarkerFailsOnWrongExceptionType(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            intdiv(1, 0); // throws \InvalidArgumentException
            ```
            MD);

        Assert::false($result->passed());
    }

    public function throwsMarkerChecksTheMessageSubstring(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            throw new \RuntimeException('order 42 not found'); // throws \RuntimeException | order 42
            ```
            MD);

        Assert::true($result->passed());
    }

    public function outputsMarkerPasses(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            echo "hello"; // outputs hello
            ```
            MD);

        Assert::true($result->passed());
    }

    public function outputsMarkerFailsOnMismatch(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            echo "hello"; // outputs goodbye
            ```
            MD);

        Assert::false($result->passed());
    }

    public function skipMarkerNeverExecutesTheStatement(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            throw new \RuntimeException('would fail if run'); // skip: illustrative only
            ```
            MD);

        Assert::true($result->passed());
        Assert::same($result->blocks[0]->statements[0]->outcome, StatementOutcome::Skip);
    }

    public function variablesPersistAcrossBlocksInTheSameSection(): void
    {
        $result = $this->check(<<<'MD'
            ## Section

            ```php doc-exec
            $counter = 1;
            ```

            ```php doc-exec
            $counter += 1;
            $counter; // => 2
            ```
            MD);

        Assert::true($result->passed());
    }

    public function aNewHeadingStartsAFreshScope(): void
    {
        $result = $this->check(<<<'MD'
            ## First

            ```php doc-exec
            $counter = 1;
            ```

            ## Second

            ```php doc-exec
            isset($counter); // => false
            ```
            MD);

        Assert::true($result->passed());
    }

    public function anEqualsMarkerOnANonExpressionStatementFailsWithADiagnostic(): void
    {
        // `echo` is a language construct, not an expression, so it has no
        // value to compare. This used to be emitted as `(echo "x")` and blew
        // up as a syntax error inside the generated script; it is now
        // rejected up front with a message naming the actual problem.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            echo "x"; // => 5
            ```
            MD);

        Assert::false($result->passed());
        Assert::same(\count($result->blocks), 1);
        Assert::null($result->blocks[0]->processError);
        Assert::string((string) $result->blocks[0]->statements[0]->message)->contains('expression statement');
    }

    public function aParseErrorIsReportedAgainstItsOwnBlockOnly(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            1 + 1; // => 2
            ```

            ```php doc-exec
            $broken = ;
            ```
            MD);

        Assert::false($result->passed());
        Assert::same(\count($result->blocks), 2);
        Assert::true($result->blocks[0]->passed);
        Assert::false($result->blocks[1]->passed);
        Assert::string((string) $result->blocks[1]->processError)->contains('parse error');
    }

    public function aProcessThatDiesWithoutReportingFailsEveryBlockThatShareTheScope(): void
    {
        // PHP CLI writes fatal-error text to stdout, not stderr, under this
        // SAPI's default display_errors — processFailure() must read both.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            $a = 1;
            ```

            ```php doc-exec
            exit(3);
            ```
            MD);

        Assert::false($result->passed());
        Assert::same(\count($result->blocks), 2);
        Assert::false($result->blocks[0]->passed);
        Assert::false($result->blocks[1]->passed);
        Assert::string((string) $result->blocks[0]->processError)->contains('exited with code 3');
    }

    public function aCommentOnlyBlockIsStillCountedAsABlock(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            // nothing runnable here
            ```
            MD);

        Assert::true($result->passed());
        Assert::same(\count($result->blocks), 1);
        Assert::same($result->blocks[0]->statements, []);
    }

    public function useImportStatementsAreNotWrappedInTryCatch(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            use Rasuvaeff\DocExec\MarkdownExtractor;

            $blocks = (new MarkdownExtractor())->extract("```php doc-exec\n1;\n```", 'x.md');
            count($blocks); // => 1
            ```
            MD);

        Assert::true($result->passed());
    }

    public function reportsOneBlockPerFencedCodeBlockNotPerStatement(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            1 + 1; // => 2
            2 + 2; // => 5
            ```
            MD);

        Assert::same(\count($result->blocks), 1);
        Assert::same(\count($result->blocks[0]->statements), 2);
        Assert::false($result->blocks[0]->passed);
    }

    public function aBlockWritingOnlyToStderrReportsThatText(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            fwrite(STDERR, "  stderr diagnostic  ");
            exit(7);
            ```
            MD);

        Assert::same($result->blocks[0]->processError, 'stderr diagnostic');
    }

    public function aBlockWritingOnlyToStdoutReportsThatText(): void
    {
        // PHP CLI puts fatal-error text on stdout under this SAPI, so stdout
        // is the stream that actually carries the diagnostic most of the time.
        // fwrite() rather than echo: echo lands in the per-statement output
        // buffer, which is what makes a doc's own printing not look like an
        // error in the first place.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            fwrite(STDOUT, "  stdout diagnostic  ");
            exit(7);
            ```
            MD);

        Assert::same($result->blocks[0]->processError, 'stdout diagnostic');
    }

    public function stderrWinsOverStdoutWhenBothArePresent(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            echo "from stdout";
            fwrite(STDERR, "from stderr");
            exit(7);
            ```
            MD);

        Assert::same($result->blocks[0]->processError, 'from stderr');
    }

    public function theEqualsMarkerComparesStateNotIdentity(): void
    {
        // var_export() renders state, so two distinct objects with equal
        // properties compare equal. This is the single most surprising
        // semantic of the primary marker, and it is documented as such:
        // `// =>` never asserts identity.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            class DocExecPoint
            {
                public function __construct(public int $x) {}
            }

            new DocExecPoint(1); // => new DocExecPoint(1)
            ```
            MD);

        Assert::true($result->passed());
    }

    public function theEqualsMarkerDistinguishesFloatsThatPrintTheSame(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            0.1 + 0.2; // => 0.3
            ```
            MD);

        Assert::false($result->passed());
        Assert::string((string) $result->blocks[0]->statements[0]->message)->contains('expected');
    }

    public function theEqualsMarkerDistinguishesAStringFromTheNumberItLooksLike(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            '5'; // => 5
            ```
            MD);

        Assert::false($result->passed());
    }

    public function theEqualsMarkerSeparatesAnObjectFromAnArrayOfTheSameData(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            (object) ['x' => 1]; // => ['x' => 1]
            ```
            MD);

        Assert::false($result->passed());
    }

    public function aMarkerKeywordWithNoPayloadFailsTheStatement(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            1 + 1; // throws
            ```
            MD);

        Assert::false($result->passed());
        Assert::string((string) $result->blocks[0]->statements[0]->message)->contains('exception class name');
    }

    public function aConstantDeclaredInABlockIsUsableByLaterStatements(): void
    {
        // `const` is compile-time: wrapped in the per-statement try/catch it
        // would be a parse error and would fail the whole scope group.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            const DOC_EXEC_GREETING = 'hi';

            DOC_EXEC_GREETING; // => 'hi'
            ```
            MD);

        Assert::true($result->passed());
    }

    public function aDocumentsOwnStrictTypesDeclarationDoesNotBreakTheRun(): void
    {
        // Copy-pasted from a real file header: the generated script already
        // declares strict_types, and PHP allows that only as the very first
        // statement, so the document's own is dropped rather than emitted.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            declare(strict_types=1);

            1 + 1; // => 2
            ```
            MD);

        Assert::true($result->passed());
    }

    public function whitespaceOnlyOnAStreamIsNotADiagnostic(): void
    {
        // Without trimming before the emptiness check, a stray newline on
        // stderr would be reported as the failure message and hide the real
        // one on stdout.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            fwrite(STDERR, "   \n  ");
            fwrite(STDOUT, "the real diagnostic");
            exit(7);
            ```
            MD);

        Assert::same($result->blocks[0]->processError, 'the real diagnostic');
    }

    public function aSilentDeathReportsTheExitCode(): void
    {
        $result = $this->check(<<<'MD'
            ```php doc-exec
            exit(9);
            ```
            MD);

        Assert::same($result->blocks[0]->processError, 'process exited with code 9 without producing a result');
    }

    public function aBlockThatNeverFinishesIsReportedAsATimeout(): void
    {
        $file = sys_get_temp_dir() . '/doc-exec-test-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($file, "```php doc-exec\nwhile (true) {}\n```\n");
        $this->tempFiles[] = $file;

        $result = (new DocExec(bootstrap: $this->bootstrap, timeoutSeconds: 1))->check($file);

        Assert::false($result->passed());
        Assert::same($result->blocks[0]->processError, 'the block did not finish within the time budget and was killed');
    }

    public function aStatementWithNoReportedResultFailsRatherThanPasses(): void
    {
        // The document truncates the results file on its way out, so the slot
        // exists but its row never arrives. A missing row must read as a
        // failure, never as a silent pass.
        $result = $this->check(<<<'MD'
            ```php doc-exec
            register_shutdown_function(static fn() => file_put_contents($argv[1], '[]'));
            1 + 1; // => 2
            ```
            MD);

        Assert::false($result->passed());
        Assert::string((string) $result->blocks[0]->statements[0]->message)->contains('no result reported');
    }

    /**
     * Self-check: for any subset of a catalog of known-good and
     * deliberately-stale blocks, doc-exec must flag exactly the stale ones
     * — no false negatives (a real doc-rot slips through) and no false
     * positives (a healthy example gets reported as broken).
     *
     * @param list<bool> $include
     */
    #[Property(runs: 120, timeoutMs: 20_000)]
    public function docExecFindsExactlyTheStaleBlocks(array $include, bool $healthyOnly): void
    {
        $catalog = $this->staleCatalog();
        $selected = [];

        foreach ($catalog as $index => $entry) {
            if ($include[$index] && (!$healthyOnly || !$entry['fail'])) {
                $selected[] = $index;
            }
        }

        if ($selected === []) {
            $selected = [0];
        }

        $markdown = '';
        $codes = [];
        $expectedFailOrdinals = [];

        foreach ($selected as $index) {
            $entry = $catalog[$index];
            $codes[] = $entry['code'];

            if ($entry['fail']) {
                $expectedFailOrdinals[] = \count($codes) - 1;
            }

            $markdown .= "```php doc-exec\n" . $entry['code'] . "\n```\n\n";
        }

        $file = sys_get_temp_dir() . '/doc-exec-property-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($file, $markdown);
        $this->tempFiles[] = $file;

        $result = (new DocExec(bootstrap: $this->bootstrap, timeoutSeconds: 5))->check($file);

        $expectedIds = array_map(
            static fn(int $ordinal): string => StableId::compute($file, $ordinal, $codes[$ordinal]),
            $expectedFailOrdinals,
        );
        sort($expectedIds);

        $actualIds = $result->failedIds();
        sort($actualIds);

        // Each of these is a distinct path through DocExec: a fully green
        // document, a stale block, a skipped statement, a marker rejected
        // before it can become broken generated code, and a block that does
        // not parse. Without the gate the random phase could quietly stop
        // reaching one of them and still report success.
        Classify::cover($expectedIds === [], 'all blocks green', 10.0);
        Classify::cover($expectedIds !== [], 'at least one stale block', 20.0);
        Classify::cover(\in_array(15, $selected, strict: true), 'a skipped statement', 15.0);
        Classify::cover(
            \in_array(16, $selected, strict: true) || \in_array(17, $selected, strict: true),
            'an invalid marker',
            15.0,
        );
        Classify::cover(\in_array(18, $selected, strict: true), 'a block that does not parse', 15.0);
        Classify::when(\count($expectedIds) > 3, 'many stale blocks');

        Assert::same($actualIds, $expectedIds);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function docExecFindsExactlyTheStaleBlocksGenerators(): array
    {
        $switches = array_fill(0, self::CATALOG_SIZE, Gen::bool());

        return [
            'include' => Gen::tuple(...$switches),
            // Without a deliberate all-green mode, twenty coin flips almost
            // never produce a document with no stale block at all, and the
            // "everything passes" path stops being exercised.
            'healthyOnly' => Gen::frequency([[3, Gen::elements([true])], [7, Gen::elements([false])]]),
        ];
    }

    /**
     * Deterministic subsets run before the random phase: the whole catalog,
     * each half of it, and the specific combinations that used to be broken
     * — a block-scoped parse error next to a healthy block (it must not take
     * its neighbour down), and every brace-bearing construct at once.
     *
     * @return iterable<string, array{list<bool>, bool}>
     */
    public static function docExecFindsExactlyTheStaleBlocksExamples(): iterable
    {
        $all = array_fill(0, self::CATALOG_SIZE, value: true);
        $none = array_fill(0, self::CATALOG_SIZE, value: false);

        yield 'every block included' => [$all, false];

        $healthy = $none;
        $stale = $none;

        foreach ([0, 2, 4, 6, 8, 10, 11, 12, 14, 15, 19] as $index) {
            $healthy[$index] = true;
        }

        foreach ([1, 3, 5, 7, 9, 13, 16, 17, 18] as $index) {
            $stale[$index] = true;
        }

        yield 'only the healthy blocks' => [$healthy, false];
        yield 'healthy-only mode over the whole catalog' => [$all, true];
        yield 'only the stale blocks' => [$stale, false];

        $parseErrorBesideHealthy = $none;
        $parseErrorBesideHealthy[0] = true;
        $parseErrorBesideHealthy[18] = true;

        yield 'a parse error next to a healthy block' => [$parseErrorBesideHealthy, false];

        $braceConstructs = $none;

        foreach ([8, 10, 11, 12, 14] as $index) {
            $braceConstructs[$index] = true;
        }

        yield 'every brace-bearing construct' => [$braceConstructs, false];
    }

    /**
     * Every entry is one block: its code and whether doc-exec must report it
     * as failed. The constructs that the old brace-splitting could not run —
     * closures, if/else, try/catch, match, anonymous classes, do/while — are
     * deliberately in here, so a regression to that behaviour shows up as a
     * property counterexample rather than as a silently narrower corpus.
     *
     * @return list<array{code: string, fail: bool}>
     */
    private function staleCatalog(): array
    {
        return [
            ['code' => '1 + 1; // => 2', 'fail' => false],
            ['code' => '1 + 1; // => 3', 'fail' => true],
            ['code' => 'intdiv(1, 0); // throws DivisionByZeroError', 'fail' => false],
            ['code' => '1 + 1; // throws \RuntimeException', 'fail' => true],
            ['code' => 'echo "hi"; // outputs hi', 'fail' => false],
            ['code' => 'echo "hi"; // outputs bye', 'fail' => true],
            ['code' => '$x = 1;', 'fail' => false],
            ['code' => 'throw new \RuntimeException("boom");', 'fail' => true],
            ['code' => "\$double = function (int \$n): int {\n    return \$n * 2;\n};\n\$double(4); // => 8", 'fail' => false],
            ['code' => "\$double = function (int \$n): int {\n    return \$n * 2;\n};\n\$double(4); // => 9", 'fail' => true],
            ['code' => "if (1 > 0) {\n    \$label = 'yes';\n} else {\n    \$label = 'no';\n}\n\$label; // => 'yes'", 'fail' => false],
            ['code' => "try {\n    throw new \RuntimeException('x');\n} catch (\RuntimeException \$e) {\n    \$seen = true;\n}\n\$seen; // => true", 'fail' => false],
            ['code' => "\$v = match (true) {\n    default => 3,\n};\n\$v; // => 3", 'fail' => false],
            ['code' => "\$o = new class {\n    public int \$n = 7;\n};\n\$o->n; // => 8", 'fail' => true],
            ['code' => "\$n = 3;\ndo {\n    --\$n;\n} while (\$n > 0);\n\$n; // => 0", 'fail' => false],
            ['code' => 'throw new \RuntimeException("never"); // skip: illustrative only', 'fail' => false],
            ['code' => '1 + 1; // =>', 'fail' => true],
            ['code' => 'echo "x"; // => 5', 'fail' => true],
            ['code' => '$broken = ;', 'fail' => true],
            ['code' => '// nothing runnable at all', 'fail' => false],
        ];
    }

    private function check(string $markdown): \Rasuvaeff\DocExec\DocumentResult
    {
        $file = sys_get_temp_dir() . '/doc-exec-test-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($file, $markdown);
        $this->tempFiles[] = $file;

        // An explicit small budget rather than the 30-second default: a
        // mutant that breaks the deadline must die quickly enough for
        // Infection to record a kill instead of skipping the mutant.
        return (new DocExec(bootstrap: $this->bootstrap, timeoutSeconds: 5))->check($file);
    }
}
