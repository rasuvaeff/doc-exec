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
}
