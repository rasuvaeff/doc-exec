<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\DocExec;
use Rasuvaeff\DocExec\StableId;
use Rasuvaeff\DocExec\StatementOutcome;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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

    /**
     * Self-check: for any subset of a catalog of known-good and
     * deliberately-stale blocks, doc-exec must flag exactly the stale ones
     * — no false negatives (a real doc-rot slips through) and no false
     * positives (a healthy example gets reported as broken).
     *
     * @param list<bool> $include
     */
    #[Property(runs: 150)]
    public function docExecFindsExactlyTheStaleBlocks(array $include): void
    {
        $catalog = self::staleCatalog();

        if (!\in_array(true, $include, true)) {
            $include[0] = true;
        }

        $markdown = '';
        $codes = [];
        $expectedFailOrdinals = [];

        foreach ($catalog as $i => $entry) {
            if ($include[$i] !== true) {
                continue;
            }

            $codes[] = $entry['code'];

            if ($entry['fail']) {
                $expectedFailOrdinals[] = \count($codes) - 1;
            }

            $markdown .= "```php doc-exec\n" . $entry['code'] . "\n```\n\n";
        }

        $file = sys_get_temp_dir() . '/doc-exec-property-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($file, $markdown);
        $this->tempFiles[] = $file;

        $result = (new DocExec(bootstrap: $this->bootstrap))->check($file);

        $expectedIds = array_map(
            static fn(int $ordinal): string => StableId::compute($file, $ordinal, $codes[$ordinal]),
            $expectedFailOrdinals,
        );
        sort($expectedIds);

        $actualIds = $result->failedIds();
        sort($actualIds);

        Assert::same($actualIds, $expectedIds);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function docExecFindsExactlyTheStaleBlocksGenerators(): array
    {
        return [
            'include' => Gen::tuple(
                Gen::bool(),
                Gen::bool(),
                Gen::bool(),
                Gen::bool(),
                Gen::bool(),
                Gen::bool(),
                Gen::bool(),
                Gen::bool(),
            ),
        ];
    }

    /**
     * Locks in one deterministic pass/fail pair per marker type, found by
     * hand while designing the catalog — run before the random phase.
     *
     * @return iterable<string, array{list<bool>}>
     */
    public static function docExecFindsExactlyTheStaleBlocksExamples(): iterable
    {
        yield 'every block included' => [[true, true, true, true, true, true, true, true]];
        yield 'only the healthy blocks' => [[true, false, true, false, true, false, true, false]];
        yield 'only the stale blocks' => [[false, true, false, true, false, true, false, true]];
    }

    /**
     * @return list<array{code: string, fail: bool}>
     */
    private static function staleCatalog(): array
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
        ];
    }

    private function check(string $markdown): \Rasuvaeff\DocExec\DocumentResult
    {
        $file = sys_get_temp_dir() . '/doc-exec-test-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($file, $markdown);
        $this->tempFiles[] = $file;

        return (new DocExec(bootstrap: $this->bootstrap))->check($file);
    }
}
