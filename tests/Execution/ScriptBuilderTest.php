<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Execution;

use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\Execution\ScriptBuilder;
use Rasuvaeff\DocExec\Marker\MarkerParser;
use Rasuvaeff\DocExec\Marker\MarkerType;
use Rasuvaeff\DocExec\Statement\StatementKind;
use Rasuvaeff\DocExec\Statement\StatementSplitter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ScriptBuilder::class)]
final class ScriptBuilderTest
{
    public function eachStatementBecomesItsOwnSlotInDocumentOrder(): void
    {
        $script = $this->build("\$a = 1;\n\$b = 2; // => 2");

        Assert::same(\count($script->slots), 2);
        Assert::same($script->slots[0]->statement->code, '$a = 1;');
        Assert::same($script->slots[1]->marker->type, MarkerType::Equals);
    }

    public function theBootstrapPathIsRequiredExactlyOnceAndSafelyQuoted(): void
    {
        $script = $this->build('$a = 1;', bootstrap: "/tmp/it's/autoload.php");

        Assert::same(substr_count($script->source, 'require_once'), 1);
        Assert::string($script->source)->contains("'/tmp/it\\'s/autoload.php'");
    }

    public function importsAreEmittedVerbatimAndTakeNoSlot(): void
    {
        // PHP only accepts `use` at file top level: wrapping it in the
        // try/catch every other statement gets would be a syntax error.
        $script = $this->build("use RuntimeException;\n\$e = new RuntimeException('x');");

        Assert::same(\count($script->slots), 1);
        Assert::same($script->slots[0]->statement->kind, StatementKind::Expression);
        Assert::string($script->source)->contains('use RuntimeException;');
        Assert::string($script->source)->notContains('try {' . "\n" . '    use RuntimeException');
    }

    public function groupUseImportsAreAlsoEmittedVerbatim(): void
    {
        $script = $this->build("use Rasuvaeff\\DocExec\\{DocExec, StableId};\n\$x = 1;");

        Assert::same(\count($script->slots), 1);
        Assert::string($script->source)->contains('use Rasuvaeff\DocExec\{DocExec, StableId};');
    }

    public function aSkippedStatementIsRecordedWithoutEmittingItsCode(): void
    {
        $script = $this->build("throw new RuntimeException('never'); // skip: illustrative");

        Assert::same($script->slots[0]->marker->type, MarkerType::Skip);
        Assert::string($script->source)->contains("'status' => 'skip'");
        Assert::string($script->source)->notContains("throw new RuntimeException('never')");
    }

    public function proseStartingWithSkipStaysExecutable(): void
    {
        $script = $this->build('$a = 1; // skip the cache warm-up in dev');

        Assert::same($script->slots[0]->marker->type, MarkerType::None);
        Assert::string($script->source)->contains('$a = 1;');
    }

    public function anEqualsMarkerOnANonExpressionStatementBecomesADiagnosticNotBrokenCode(): void
    {
        $script = $this->build('echo "x"; // => 5');

        Assert::same($script->slots[0]->marker->type, MarkerType::Invalid);
        Assert::string($script->source)->contains('expression statement');
        Assert::string($script->source)->notContains('(echo "x")');
    }

    public function anEmptyEqualsMarkerBecomesADiagnosticNotAnEmptyExpression(): void
    {
        $script = $this->build('1 + 1; // =>');

        Assert::same($script->slots[0]->marker->type, MarkerType::Invalid);
        Assert::string($script->source)->notContains('= ();');
    }

    public function aBlockThatDoesNotParseIsRejectedWithoutPoisoningTheScript(): void
    {
        $blocks = [
            new CodeBlock(file: 'a.md', ordinal: 0, startLine: 1, code: '$ok = 1;', scopeKey: ''),
            new CodeBlock(file: 'a.md', ordinal: 1, startLine: 5, code: '$broken = ;', scopeKey: ''),
        ];

        $script = (new ScriptBuilder())->build($blocks, '/tmp/autoload.php');

        Assert::same(\count($script->blockFailures), 1);
        Assert::same($script->blockFailures[0]->block->ordinal, 1);
        Assert::string($script->blockFailures[0]->message)->contains('parse error on line 1');
        Assert::same(\count($script->slots), 1);
        Assert::string($script->source)->contains('$ok = 1;');
        Assert::string($script->source)->notContains('$broken');
    }

    public function theThrowsMarkerComparesClassAndMessageSubstring(): void
    {
        $script = $this->build("intdiv(1, 0); // throws DivisionByZeroError | Division by zero");

        Assert::same($script->slots[0]->marker->type, MarkerType::Throws);
        Assert::string($script->source)->contains("'DivisionByZeroError'");
        Assert::string($script->source)->contains('str_contains($__docexec_e->getMessage()');
    }

    public function theOutputsMarkerComparesTrimmedCapturedOutput(): void
    {
        $script = $this->build('echo "done"; // outputs done');

        Assert::same($script->slots[0]->marker->type, MarkerType::Outputs);
        Assert::string($script->source)->contains("\$__docexec_expected = 'done';");
        Assert::string($script->source)->contains('trim($__docexec_out) === trim($__docexec_expected)');
    }

    public function everyBlockOfAScopeGroupSharesOneScript(): void
    {
        $blocks = [
            new CodeBlock(file: 'a.md', ordinal: 0, startLine: 1, code: '$shared = 1;', scopeKey: 'k'),
            new CodeBlock(file: 'a.md', ordinal: 1, startLine: 5, code: '$shared + 1; // => 2', scopeKey: 'k'),
        ];

        $script = (new ScriptBuilder())->build($blocks, '/tmp/autoload.php');

        Assert::same(\count($script->slots), 2);
        Assert::same($script->slots[0]->block->ordinal, 0);
        Assert::same($script->slots[1]->block->ordinal, 1);
        Assert::same(substr_count($script->source, 'require_once'), 1);
    }

    public function theResultsAreWrittenAsAJsonListToThePathGivenAsTheFirstArgument(): void
    {
        // Not a file descriptor above 2: Windows cannot expose those to a
        // child process, which would make the whole package POSIX-only.
        $script = $this->build('$a = 1;');

        Assert::string($script->source)->contains('$__docexec_results_file = $argv[1];');
        Assert::string($script->source)->contains('file_put_contents($__docexec_results_file, json_encode($__docexec_results');
        Assert::string($script->source)->notContains('php://fd/3');
    }

    public function anEmptyBlockProducesNoSlotsAndNoFailures(): void
    {
        $script = $this->build("// just a comment\n");

        Assert::same($script->slots, []);
        Assert::same($script->blockFailures, []);
    }

    private function build(string $code, string $bootstrap = '/tmp/autoload.php'): \Rasuvaeff\DocExec\Execution\GeneratedScript
    {
        $block = new CodeBlock(file: 'a.md', ordinal: 0, startLine: 1, code: $code, scopeKey: '');

        return (new ScriptBuilder())->build([$block], $bootstrap);
    }

    public function theGeneratedScriptHasTheExactPreambleAndEpilogue(): void
    {
        $script = $this->build('$a = 1;', bootstrap: '/tmp/autoload.php');

        Assert::true(str_starts_with($script->source, "<?php\n\ndeclare(strict_types=1);\n\n"));
        Assert::string($script->source)->contains("require_once '/tmp/autoload.php';\n\n");
        Assert::string($script->source)->contains("\$__docexec_results = [];\n\n");
        Assert::true(str_ends_with(
            $script->source,
            "\nfile_put_contents(\$__docexec_results_file, "
            . "json_encode(\$__docexec_results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));\n",
        ));
    }

    public function theGeneratedScriptRunsAsRealPhp(): void
    {
        // The preamble/epilogue assertions above pin the text; this pins that
        // the text is executable and reports one row per slot.
        $script = $this->build("\$a = 2;\n\$a * 3; // => 6", bootstrap: \dirname(__DIR__, 2) . '/vendor/autoload.php');

        $outcome = (new \Rasuvaeff\DocExec\Execution\ProcessRunner())->run($script->source);

        Assert::same($outcome->results, [['status' => 'pass', 'output' => ''], [
            'status' => 'pass',
            'actual' => '6',
            'expected' => '6',
            'output' => '',
        ]]);
    }

    public function theThrowsMarkerReportsTheExpectedClassWhenNothingIsThrown(): void
    {
        $script = $this->build('1 + 1; // throws RuntimeException');

        Assert::string($script->source)->contains("'expected throw of ' . 'RuntimeException'");
    }

    public function aLeadingBackslashIsStrippedFromTheExpectedExceptionClass(): void
    {
        $script = $this->build('1 + 1; // throws \\RuntimeException');

        Assert::string($script->source)->contains("\$__docexec_class = 'RuntimeException';");
    }

    public function injectedCollaboratorsAreUsedInsteadOfTheDefaults(): void
    {
        $block = new CodeBlock(file: 'a.md', ordinal: 0, startLine: 1, code: '$a = 1; // => 1', scopeKey: '');

        $script = (new ScriptBuilder(new StatementSplitter(), new MarkerParser()))->build([$block], '/tmp/autoload.php');

        Assert::same(\count($script->slots), 1);
        Assert::same($script->slots[0]->marker->type, MarkerType::Equals);
    }

    public function consecutiveImportsEachGetTheirOwnLine(): void
    {
        $script = $this->build("use RuntimeException;\nuse LogicException;\n\$a = 1;");

        Assert::string($script->source)->contains("use RuntimeException;\n");
        Assert::string($script->source)->contains("use LogicException;\n");
        Assert::string($script->source)->notContains('use RuntimeException;use LogicException;');
    }

    public function aBlockThatOnlyFailsToParseProducesNoSlotsAtAll(): void
    {
        $block = new CodeBlock(file: 'a.md', ordinal: 0, startLine: 1, code: '$broken = ;', scopeKey: '');

        $script = (new ScriptBuilder())->build([$block], '/tmp/autoload.php');

        Assert::same($script->slots, []);
        Assert::same(\count($script->blockFailures), 1);
    }

    public function theParseErrorMessageNamesTheLineInsideTheBlock(): void
    {
        $block = new CodeBlock(file: 'a.md', ordinal: 0, startLine: 40, code: "\$a = 1;\n\$b = 1;\n\$c = ;", scopeKey: '');

        $script = (new ScriptBuilder())->build([$block], '/tmp/autoload.php');

        Assert::string($script->blockFailures[0]->message)->contains('parse error on line 3 of the block');
    }

    public function theOutputsMarkerQuotesTheExpectedTextSafely(): void
    {
        $script = $this->build("echo 'x'; // outputs it's here");

        Assert::string($script->source)->contains("'it\\'s here'");
    }
}
