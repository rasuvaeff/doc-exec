<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\MarkdownExtractor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MarkdownExtractor::class)]
final class MarkdownExtractorTest
{
    public function extractsOnlyDocExecOptInBlocks(): void
    {
        $markdown = <<<MARKDOWN
            # Title

            ```php doc-exec
            1 + 1; // => 2
            ```

            ```php
            \$illustrative = true;
            ```

            ```bash
            echo hi
            ```
            MARKDOWN;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'README.md');

        Assert::same(\count($blocks), 1);
        Assert::string($blocks[0]->code)->contains('1 + 1');
    }

    public function strictModeAlsoExecutesBarePhpBlocks(): void
    {
        $markdown = <<<MARKDOWN
            ```php
            1 + 1; // => 2
            ```
            MARKDOWN;

        $blocks = (new MarkdownExtractor(strict: true))->extract($markdown, 'README.md');

        Assert::same(\count($blocks), 1);
    }

    public function ordinalsAreSequentialAcrossExecutableBlocksOnly(): void
    {
        $markdown = <<<MARKDOWN
            ```php doc-exec
            1;
            ```

            ```php
            2;
            ```

            ```php doc-exec
            3;
            ```
            MARKDOWN;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'README.md');

        Assert::same(\count($blocks), 2);
        Assert::same($blocks[0]->ordinal, 0);
        Assert::same($blocks[1]->ordinal, 1);
    }

    public function scopeKeyChangesOnEachHeadingAndStaysStableWithin(): void
    {
        $markdown = <<<MARKDOWN
            ## Setup

            ```php doc-exec
            \$x = 1;
            ```

            ```php doc-exec
            \$x; // => 1
            ```

            ## Another section

            ```php doc-exec
            \$y = 2;
            ```
            MARKDOWN;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'README.md');

        Assert::same(\count($blocks), 3);
        Assert::same($blocks[0]->scopeKey, $blocks[1]->scopeKey);
        Assert::false($blocks[1]->scopeKey === $blocks[2]->scopeKey);
    }

    public function startLineIsFirstLineOfBlockContent(): void
    {
        $markdown = "line1\nline2\n```php doc-exec\n1 + 1; // => 2\n```\n";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'README.md');

        Assert::same($blocks[0]->startLine, 4);
    }

    public function tildeFencesAreSupported(): void
    {
        $markdown = <<<MARKDOWN
            ~~~php doc-exec
            1 + 1; // => 2
            ~~~
            MARKDOWN;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'README.md');

        Assert::same(\count($blocks), 1);
    }

    public function aFenceIndentedInsideAListItemIsExtractedAndDedented(): void
    {
        $markdown = <<<'MD'
            1. First install it, then:

               ```php doc-exec
               $a = 1;
               $a + 1; // => 2
               ```
            MD;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'list.md');

        Assert::same(\count($blocks), 1);
        Assert::same($blocks[0]->code, "\$a = 1;\n\$a + 1; // => 2");
    }

    public function aSetextHeadingStartsAFreshScope(): void
    {
        $markdown = <<<'MD'
            First
            =====

            ```php doc-exec
            $a = 1;
            ```

            Second
            ------

            ```php doc-exec
            $b = 2;
            ```
            MD;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'setext.md');

        Assert::same(\count($blocks), 2);
        Assert::true($blocks[0]->scopeKey !== $blocks[1]->scopeKey);
    }

    public function aFenceLeftOpenAtEndOfFileIsStillExtracted(): void
    {
        $markdown = "```php doc-exec
\$a = 1;";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'unclosed.md');

        Assert::same(\count($blocks), 1);
        Assert::same($blocks[0]->code, '$a = 1;');
    }

    public function aBlockNestedInAWiderFenceIsNotExtracted(): void
    {
        // This is what lets a README document doc-exec's own syntax without
        // the documentation example being executed as a test.
        $markdown = <<<'MD'
            ````markdown
            ```php doc-exec
            $never = 'executed';
            ```
            ````
            MD;

        Assert::same((new MarkdownExtractor())->extract($markdown, 'nested.md'), []);
    }

    public function anIndentedHeadingStillResetsTheScope(): void
    {
        $markdown = <<<'MD'
              ## Indented heading

            ```php doc-exec
            $a = 1;
            ```
            MD;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'indented.md');

        Assert::same(\count($blocks), 1);
        Assert::string($blocks[0]->scopeKey)->contains('Indented heading');
    }
}
