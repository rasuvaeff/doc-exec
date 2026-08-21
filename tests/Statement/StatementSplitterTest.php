<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Statement;

use Rasuvaeff\DocExec\Statement\StatementSplitter;
use Testo\Assert;
use Testo\Codecov\Covers;
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
}
