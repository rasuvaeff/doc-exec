<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\BlockResult;
use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\DocumentResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DocumentResult::class)]
final class DocumentResultTest
{
    public function passedIsTrueWithNoBlocks(): void
    {
        Assert::true((new DocumentResult(file: 'README.md', blocks: []))->passed());
    }

    public function passedIsTrueWhenEveryBlockPassed(): void
    {
        $result = new DocumentResult(file: 'README.md', blocks: [
            $this->block(0, passed: true),
            $this->block(1, passed: true),
        ]);

        Assert::true($result->passed());
    }

    public function passedIsFalseWhenAnyBlockFailed(): void
    {
        $result = new DocumentResult(file: 'README.md', blocks: [
            $this->block(0, passed: true),
            $this->block(1, passed: false),
        ]);

        Assert::false($result->passed());
    }

    public function failedIdsIsEmptyWhenEverythingPassed(): void
    {
        $result = new DocumentResult(file: 'README.md', blocks: [$this->block(0, passed: true)]);

        Assert::same($result->failedIds(), []);
    }

    public function failedIdsListsOnlyTheFailingBlockIds(): void
    {
        $result = new DocumentResult(file: 'README.md', blocks: [
            $this->block(0, passed: true, stableId: 'ok-id'),
            $this->block(1, passed: false, stableId: 'bad-id'),
        ]);

        Assert::same($result->failedIds(), ['bad-id']);
    }

    private function block(int $ordinal, bool $passed, string $stableId = 'id'): BlockResult
    {
        $codeBlock = new CodeBlock(file: 'README.md', ordinal: $ordinal, startLine: 1, code: '1;', scopeKey: '');

        return new BlockResult(block: $codeBlock, stableId: $stableId, statements: [], passed: $passed);
    }
}
