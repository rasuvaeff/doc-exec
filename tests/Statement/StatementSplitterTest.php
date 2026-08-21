<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Statement;

use Rasuvaeff\DocExec\Statement\StatementKind;
use Rasuvaeff\DocExec\Statement\StatementParseError;
use Rasuvaeff\DocExec\Statement\StatementSplitter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(StatementSplitter::class)]
final class StatementSplitterTest
{
    public function splitsTwoSimpleStatements(): void
    {
        $statements = (new StatementSplitter())->split("\$a = 1;\n\$b = 2; // => 2");

        Assert::same(\count($statements), 2);
        Assert::same($statements[0]->code, '$a = 1;');
        Assert::null($statements[0]->trailingComment);
        Assert::same($statements[1]->code, '$b = 2;');
        Assert::same($statements[1]->trailingComment, '=> 2');
    }

    public function keepsAMultiLineStatementTogether(): void
    {
        $code = <<<'PHP'
            $items = [
                1,
                2,
            ];
            $count = count($items); // => 2
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 2);
        Assert::string($statements[0]->code)->contains("1,\n    2,");
    }

    public function keepsAForeachBlockAsOneStatement(): void
    {
        $code = <<<'PHP'
            $sum = 0;
            foreach ([1, 2, 3] as $n) {
                $sum += $n;
            }
            $sum; // => 6
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 3);
        Assert::string($statements[1]->code)->contains('foreach');
        Assert::null($statements[1]->trailingComment);
        Assert::same($statements[2]->trailingComment, '=> 6');
    }

    public function commentOnItsOwnLineDoesNotAttach(): void
    {
        $code = "1 + 1;\n// => 2\n";

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 1);
        Assert::null($statements[0]->trailingComment);
    }

    public function trailingStatementWithoutSemicolonIsStillCaptured(): void
    {
        $statements = (new StatementSplitter())->split('1 + 1');

        Assert::same(\count($statements), 1);
        Assert::same($statements[0]->code, '1 + 1');
    }

    public function keepsAClosureAssignmentAsOneStatement(): void
    {
        $code = <<<'PHP'
            $double = function (int $x): int {
                return $x * 2;
            };
            $double(4); // => 8
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 2);
        Assert::string($statements[0]->code)->contains('function (int $x)');
        Assert::true(str_ends_with($statements[0]->code, '};'));
        Assert::same($statements[1]->trailingComment, '=> 8');
    }

    public function keepsIfElseAsOneStatement(): void
    {
        $code = <<<'PHP'
            $n = 5;
            if ($n > 1) {
                $label = 'big';
            } else {
                $label = 'small';
            }
            $label; // => 'big'
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 3);
        Assert::string($statements[1]->code)->contains('else');
        Assert::same($statements[2]->trailingComment, "=> 'big'");
    }

    public function keepsTryCatchAsOneStatement(): void
    {
        $code = <<<'PHP'
            $caught = null;
            try {
                throw new RuntimeException('boom');
            } catch (RuntimeException $e) {
                $caught = $e->getMessage();
            }
            $caught; // => 'boom'
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 3);
        Assert::string($statements[1]->code)->contains('catch');
        Assert::true(str_ends_with($statements[1]->code, '}'));
    }

    public function keepsAMatchExpressionAsOneStatement(): void
    {
        $code = <<<'PHP'
            $value = match (true) {
                1 > 2 => 'no',
                default => 'yes',
            };
            $value; // => 'yes'
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 2);
        Assert::true(str_ends_with($statements[0]->code, '};'));
        Assert::same($statements[1]->trailingComment, "=> 'yes'");
    }

    public function keepsAnAnonymousClassAsOneStatement(): void
    {
        $code = <<<'PHP'
            $object = new class {
                public int $n = 7;
            };
            $object->n; // => 7
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 2);
        Assert::string($statements[0]->code)->contains('new class');
        Assert::true(str_ends_with($statements[0]->code, '};'));
    }

    public function keepsDoWhileAsOneStatement(): void
    {
        $code = <<<'PHP'
            $n = 3;
            do {
                --$n;
            } while ($n > 0);
            $n; // => 0
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 3);
        Assert::string($statements[1]->code)->contains('while ($n > 0);');
    }

    public function reportsTheFirstLineOfAMultiLineStatement(): void
    {
        $code = <<<'PHP'
            $a = 1;
            $items = [
                1,
                2,
            ];
            PHP;

        $statements = (new StatementSplitter())->split($code);

        Assert::same($statements[1]->line, 2);
    }

    #[DataProvider('statementKindProvider')]
    public function everyStatementCarriesWhatPhpConsidersIt(string $code, StatementKind $expected): void
    {
        $statements = (new StatementSplitter())->split($code);

        Assert::same($statements[0]->kind, $expected);
    }

    public static function statementKindProvider(): iterable
    {
        yield 'expression' => ['$a = 1;', StatementKind::Expression];
        yield 'call' => ['strlen("x");', StatementKind::Expression];
        yield 'class import' => ['use RuntimeException;', StatementKind::Import];
        yield 'function import' => ['use function array_map;', StatementKind::Import];
        yield 'const import' => ['use const PHP_EOL;', StatementKind::Import];
        yield 'group import' => ['use Rasuvaeff\DocExec\{DocExec, StableId};', StatementKind::Import];
        yield 'constant' => ["const DOC_EXEC_GREETING = 'hi';", StatementKind::Constant];
        yield 'strict types declaration' => ['declare(strict_types=1);', StatementKind::FileDeclaration];
        yield 'encoding declaration' => ["declare(encoding='UTF-8');", StatementKind::FileDeclaration];
        yield 'ticks declaration' => ['declare(ticks=1);', StatementKind::Other];
        yield 'echo' => ['echo "x";', StatementKind::Other];
        yield 'if' => ['if (true) { $a = 1; }', StatementKind::Other];
        yield 'function declaration' => ['function f(): int { return 1; }', StatementKind::Other];
        yield 'foreach' => ['foreach ([1] as $n) { $a = $n; }', StatementKind::Other];
    }

    #[DataProvider('trailingCommentProvider')]
    public function trailingCommentsAreStrippedOfTheirCommentSyntax(string $code, ?string $expected): void
    {
        $statements = (new StatementSplitter())->split($code);

        Assert::same($statements[0]->trailingComment, $expected);
    }

    public static function trailingCommentProvider(): iterable
    {
        yield 'double slash' => ['$a = 1; // => 1', '=> 1'];
        yield 'hash' => ['$a = 1; # => 1', '=> 1'];
        yield 'block comment' => ['$a = 1; /* => 1 */', '=> 1'];
        yield 'no comment' => ['$a = 1;', null];
        yield 'comment after a multi-line construct' => ["foreach ([1] as \$n) {\n    \$a = \$n;\n} // outputs nothing", 'outputs nothing'];
        yield 'code after the statement, not a comment' => ['$a = 1; $b = 2; // => 2', null];
    }

    public function aCommentOnTheNextLineBelongsToNobody(): void
    {
        $statements = (new StatementSplitter())->split("\$a = 1;\n// => 1\n\$b = 2;");

        Assert::null($statements[0]->trailingComment);
        Assert::null($statements[1]->trailingComment);
    }

    public function aBlockThatDoesNotParseCarriesTheLineInsideTheBlock(): void
    {
        Expect::exception(StatementParseError::class);

        (new StatementSplitter())->split("\$a = 1;\n\$b = ;");
    }

    public function theParseErrorLineIsRelativeToTheBlockNotTheGeneratedSource(): void
    {
        $line = null;

        try {
            (new StatementSplitter())->split("\$a = 1;\n\$b = 1;\n\$c = ;");
        } catch (StatementParseError $error) {
            $line = $error->blockLine;
        }

        Assert::same($line, 3);
    }

    public function anEmptyBlockYieldsNoStatements(): void
    {
        Assert::same((new StatementSplitter())->split(''), []);
    }

    public function aCommentOnlyBlockYieldsNoStatements(): void
    {
        Assert::same((new StatementSplitter())->split("// nothing here\n# nor here"), []);
    }

    public function statementTextIsTheVerbatimSourceIncludingItsSemicolon(): void
    {
        $statements = (new StatementSplitter())->split('$a  =   1 ;');

        Assert::same($statements[0]->code, '$a  =   1 ;');
    }

    public function aTrailingExpressionWithoutASemicolonKeepsItsOriginalText(): void
    {
        $statements = (new StatementSplitter())->split("\$a = 1;\n\$a + 1 // => 2");

        Assert::same(\count($statements), 2);
        Assert::same($statements[1]->code, '$a + 1');
        Assert::same($statements[1]->trailingComment, '=> 2');
    }

    public function heredocContentIsNotMistakenForCode(): void
    {
        $code = "\$text = <<<TXT\n\$a = 1; // => 5\nTXT;\n\$done = true;";

        $statements = (new StatementSplitter())->split($code);

        Assert::same(\count($statements), 2);
        Assert::null($statements[0]->trailingComment);
    }

    public function everyStatementReportsTheLineItStartsOn(): void
    {
        $code = "\$a = 1;\n\n\$b = 2;\n\nif (true) {\n    \$c = 3;\n}";

        $statements = (new StatementSplitter())->split($code);

        Assert::same($statements[0]->line, 1);
        Assert::same($statements[1]->line, 3);
        Assert::same($statements[2]->line, 5);
    }

    public function strayEmptyStatementsAreNotStatements(): void
    {
        // `;;` produces Nop nodes: counting them would create slots with no
        // code and shift every marker onto the wrong statement.
        $statements = (new StatementSplitter())->split('$a = 1;;;$b = 2;');

        Assert::same(\count($statements), 2);
        Assert::same($statements[0]->code, '$a = 1;');
        Assert::same($statements[1]->code, '$b = 2;');
    }

    public function aTrailingCommentIsNotStolenByAFollowingEmptyStatement(): void
    {
        $statements = (new StatementSplitter())->split("\$a = 1; // => 1\n;");

        Assert::same($statements[0]->trailingComment, '=> 1');
    }
}
