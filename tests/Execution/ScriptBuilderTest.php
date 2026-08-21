<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Execution;

use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\Execution\ScriptBuilder;
use Rasuvaeff\DocExec\Marker\MarkerType;
use Rasuvaeff\DocExec\Statement\StatementKind;
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

    public function theResultsChannelIsWrittenAsAJsonListOnDescriptorThree(): void
    {
        $script = $this->build('$a = 1;');

        Assert::string($script->source)->contains("fopen('php://fd/3', 'w')");
        Assert::string($script->source)->contains('json_encode($__docexec_results');
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
}
