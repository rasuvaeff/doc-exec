<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Report;

use Rasuvaeff\DocExec\BlockResult;
use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\DocumentResult;
use Rasuvaeff\DocExec\Marker\ParsedMarker;
use Rasuvaeff\DocExec\Report\ConsoleReporter;
use Rasuvaeff\DocExec\Statement\Statement;
use Rasuvaeff\DocExec\StatementOutcome;
use Rasuvaeff\DocExec\StatementResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConsoleReporter::class)]
final class ConsoleReporterTest
{
    public function reportsAllPassedWhenNothingFailed(): void
    {
        $block = new CodeBlock(file: 'README.md', ordinal: 0, startLine: 3, code: '1;', scopeKey: '');
        $result = new DocumentResult(file: 'README.md', blocks: [
            new BlockResult(block: $block, stableId: 'abc', statements: [], passed: true),
        ]);

        $rendered = (new ConsoleReporter())->render([$result]);

        Assert::string($rendered)->contains('1/1 blocks passed');
    }

    public function reportsTheFailingBlockLocationAndMessage(): void
    {
        $block = new CodeBlock(file: 'README.md', ordinal: 0, startLine: 42, code: '2 + 3; // => 6', scopeKey: '');
        $statement = new Statement(code: '2 + 3;', line: 1, trailingComment: '=> 6');
        $statementResult = new StatementResult(
            statement: $statement,
            marker: ParsedMarker::equals('6'),
            outcome: StatementOutcome::Fail,
            message: 'expected 6, got 5',
        );
        $result = new DocumentResult(file: 'README.md', blocks: [
            new BlockResult(block: $block, stableId: 'abc', statements: [$statementResult], passed: false),
        ]);

        $rendered = (new ConsoleReporter())->render([$result]);

        Assert::string($rendered)->contains('README.md:42');
        Assert::string($rendered)->contains('expected 6, got 5');
        Assert::string($rendered)->contains('1/1 blocks failed');
    }

    public function reportsAProcessLevelFailureWithoutStatements(): void
    {
        $block = new CodeBlock(file: 'README.md', ordinal: 0, startLine: 5, code: 'broken(', scopeKey: '');
        $result = new DocumentResult(file: 'README.md', blocks: [
            new BlockResult(
                block: $block,
                stableId: 'abc',
                statements: [],
                passed: false,
                processError: 'PHP Parse error: syntax error',
            ),
        ]);

        $rendered = (new ConsoleReporter())->render([$result]);

        Assert::string($rendered)->contains('failed to execute');
        Assert::string($rendered)->contains('PHP Parse error');
    }

    public function theTotalCountsEveryBlockAcrossEveryDocument(): void
    {
        $render = static fn(string $file, bool ...$passed): DocumentResult => new DocumentResult(
            file: $file,
            blocks: array_map(
                static fn(bool $ok, int $ordinal): BlockResult => new BlockResult(
                    block: new CodeBlock(file: $file, ordinal: $ordinal, startLine: 1, code: '1;', scopeKey: ''),
                    stableId: $file . $ordinal,
                    statements: [],
                    passed: $ok,
                    processError: $ok ? null : 'boom',
                ),
                $passed,
                array_keys($passed),
            ),
        );

        $rendered = (new ConsoleReporter())->render([
            $render('a.md', true, false, true),
            $render('b.md', false),
        ]);

        Assert::string($rendered)->contains('2/4 blocks failed');
    }
}
