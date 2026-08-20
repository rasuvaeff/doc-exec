<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Benchmarks;

use Rasuvaeff\DocExec\Statement\StatementSplitter;
use Testo\Bench;

final class StatementSplitterBench
{
    private const string SAMPLE = <<<'PHP'
        $items = [1, 2, 3, 4, 5];
        $sum = array_sum($items); // => 15
        foreach ($items as $n) {
            $sum += $n;
        }
        $sum; // => 30
        PHP;

    #[Bench(
        callables: [
            'naive_explode' => [self::class, 'naiveLineSplit'],
        ],
        arguments: [self::SAMPLE],
        calls: 10_000,
        iterations: 10,
    )]
    public static function tokenizerSplit(string $code): array
    {
        return (new StatementSplitter())->split($code);
    }

    /**
     * @return list<string>
     */
    public static function naiveLineSplit(string $code): array
    {
        return explode("\n", $code);
    }
}
