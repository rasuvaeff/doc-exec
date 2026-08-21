<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\MarkdownExtractor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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

    public function twoIdenticalHeadingsStartDifferentScopes(): void
    {
        // The scope key carries the heading's line number, so repeating a
        // heading text does not merge two sections into one scope.
        $markdown = <<<'MD'
            ## Usage

            ```php doc-exec
            $a = 1;
            ```

            ## Usage

            ```php doc-exec
            $b = 2;
            ```
            MD;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'repeat.md');

        Assert::true($blocks[0]->scopeKey !== $blocks[1]->scopeKey);
        Assert::string($blocks[0]->scopeKey)->contains(':Usage');
        Assert::string($blocks[1]->scopeKey)->contains(':Usage');
    }

    public function aSetextScopeKeyCarriesTheHeadingText(): void
    {
        $markdown = "Title here\n=====\n\n```php doc-exec\n\$a = 1;\n```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'setext.md');

        Assert::string($blocks[0]->scopeKey)->contains('Title here');
    }

    public function anUnderlineWithoutTextAboveItIsNotAHeading(): void
    {
        $markdown = "\n-----\n\n```php doc-exec\n\$a = 1;\n```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'rule.md');

        Assert::same($blocks[0]->scopeKey, '');
    }

    public function blocksUnderOneHeadingShareAScopeKey(): void
    {
        $markdown = <<<'MD'
            ## One

            ```php doc-exec
            $a = 1;
            ```

            ```php doc-exec
            $a + 1; // => 2
            ```
            MD;

        $blocks = (new MarkdownExtractor())->extract($markdown, 'shared.md');

        Assert::same($blocks[0]->scopeKey, $blocks[1]->scopeKey);
    }

    #[DataProvider('infoStringProvider')]
    public function onlyTheOptInInfoStringMakesABlockExecutable(string $info, bool $executable): void
    {
        $markdown = "```{$info}\n\$a = 1;\n```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'info.md');

        Assert::same($blocks !== [], $executable);
    }

    public static function infoStringProvider(): iterable
    {
        yield 'exact' => ['php doc-exec', true];
        yield 'mixed case' => ['PHP Doc-Exec', true];
        yield 'padded' => ['php doc-exec   ', true];
        yield 'bare php' => ['php', false];
        yield 'other language' => ['bash', false];
        yield 'similar prefix' => ['php doc-exec-plan', false];
        yield 'reversed' => ['doc-exec php', false];
        yield 'empty' => ['', false];
    }

    public function aBareFenceIsExecutableOnlyInStrictMode(): void
    {
        $markdown = "```php\n\$a = 1;\n```";

        Assert::same((new MarkdownExtractor())->extract($markdown, 'strict.md'), []);
        Assert::same(\count((new MarkdownExtractor(strict: true))->extract($markdown, 'strict.md')), 1);
    }

    public function aTildeFenceIsSupportedAndIsNotClosedByBackticks(): void
    {
        $markdown = "~~~php doc-exec\n\$a = 1;\n```\n~~~";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'tilde.md');

        Assert::same(\count($blocks), 1);
        Assert::string($blocks[0]->code)->contains('```');
    }

    public function aClosingFenceMustBeAtLeastAsLongAsTheOpeningOne(): void
    {
        // A shorter run of backticks is content, not the end of the block.
        $markdown = "````php doc-exec\n\$a = 1;\n```\n````";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'long.md');

        Assert::same(\count($blocks), 1);
        Assert::string($blocks[0]->code)->contains('```');
    }

    public function aClosingFenceCarryingAnInfoStringIsNotAClosingFence(): void
    {
        $markdown = "```php doc-exec\n\$a = 1;\n``` trailing\n```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'trailing.md');

        Assert::same(\count($blocks), 1);
        Assert::string($blocks[0]->code)->contains('``` trailing');
    }

    public function theFirstLineOfTheCodeIsReportedNotTheFenceLine(): void
    {
        $markdown = "intro\n\n```php doc-exec\n\$a = 1;\n```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'lines.md');

        Assert::same($blocks[0]->startLine, 4);
    }

    public function blocksAreNumberedInDocumentOrderFromZero(): void
    {
        $markdown = "```php doc-exec\n\$a = 1;\n```\n\n```bash\necho hi\n```\n\n```php doc-exec\n\$b = 2;\n```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'ordinals.md');

        Assert::same(\count($blocks), 2);
        Assert::same($blocks[0]->ordinal, 0);
        Assert::same($blocks[1]->ordinal, 1);
    }

    public function onlyTheOpeningFencesIndentationIsStripped(): void
    {
        // Relative indentation inside the block is part of the code.
        $markdown = "  ```php doc-exec\n  if (true) {\n      \$a = 1;\n  }\n  ```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'dedent.md');

        Assert::same($blocks[0]->code, "if (true) {\n    \$a = 1;\n}");
    }

    public function aLineIndentedLessThanTheFenceIsNotOverStripped(): void
    {
        $markdown = "    ```php doc-exec\n  \$a = 1;\n    ```";

        $blocks = (new MarkdownExtractor())->extract($markdown, 'ragged.md');

        Assert::same($blocks[0]->code, '$a = 1;');
    }

    public function aHeadingWithoutTextIsNotAHeading(): void
    {
        $markdown = "#\n\n```php doc-exec\n\$a = 1;\n```";

        Assert::same((new MarkdownExtractor())->extract($markdown, 'hash.md')[0]->scopeKey, '');
    }

    public function sevenHashesAreNotAHeading(): void
    {
        $markdown = "####### Deep\n\n```php doc-exec\n\$a = 1;\n```";

        Assert::same((new MarkdownExtractor())->extract($markdown, 'deep.md')[0]->scopeKey, '');
    }
}
