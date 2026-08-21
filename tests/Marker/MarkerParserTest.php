<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Marker;

use Rasuvaeff\DocExec\Marker\MarkerParser;
use Rasuvaeff\DocExec\Marker\MarkerType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MarkerParser::class)]
final class MarkerParserTest
{
    public function nullCommentParsesAsNone(): void
    {
        $marker = (new MarkerParser())->parse(null);

        Assert::same($marker->type, MarkerType::None);
    }

    public function plainHumanCommentParsesAsNone(): void
    {
        $marker = (new MarkerParser())->parse('increment the counter');

        Assert::same($marker->type, MarkerType::None);
    }

    public function equalsMarkerCapturesTheExpression(): void
    {
        $marker = (new MarkerParser())->parse('=> [1, 2, 3]');

        Assert::same($marker->type, MarkerType::Equals);
        Assert::same($marker->expression, '[1, 2, 3]');
    }

    public function throwsMarkerWithoutSubstring(): void
    {
        $marker = (new MarkerParser())->parse('throws DivisionByZeroError');

        Assert::same($marker->type, MarkerType::Throws);
        Assert::same($marker->exceptionClass, 'DivisionByZeroError');
        Assert::null($marker->exceptionSubstring);
    }

    public function throwsMarkerWithSubstring(): void
    {
        $marker = (new MarkerParser())->parse('throws \InvalidArgumentException | must be positive');

        Assert::same($marker->type, MarkerType::Throws);
        Assert::same($marker->exceptionClass, '\InvalidArgumentException');
        Assert::same($marker->exceptionSubstring, 'must be positive');
    }

    public function outputsMarkerCapturesRestOfLine(): void
    {
        $marker = (new MarkerParser())->parse('outputs Hello, world!');

        Assert::same($marker->type, MarkerType::Outputs);
        Assert::same($marker->expectedOutput, 'Hello, world!');
    }

    #[DataProvider('skipVariantsProvider')]
    public function skipMarkerCapturesOptionalReason(string $comment, ?string $expectedReason): void
    {
        $marker = (new MarkerParser())->parse($comment);

        Assert::same($marker->type, MarkerType::Skip);
        Assert::same($marker->skipReason, $expectedReason);
    }

    public static function skipVariantsProvider(): iterable
    {
        yield 'bare skip' => ['skip', null];
        yield 'skip with colon reason' => ['skip: network call', 'network call'];
    }

    #[DataProvider('proseCommentProvider')]
    public function proseIsNotAMarker(string $comment): void
    {
        Assert::same((new MarkerParser())->parse($comment)->type, MarkerType::None);
    }

    public static function proseCommentProvider(): iterable
    {
        yield 'skip-prefixed prose' => ['skip this in production'];
        yield 'hyphenated word' => ['skip-not'];
        yield 'plain sentence' => ['this is just a note'];
    }

    public function emptyEqualsMarkerIsInvalidRatherThanEmptyExpression(): void
    {
        $marker = (new MarkerParser())->parse('=>');

        Assert::same($marker->type, MarkerType::Invalid);
        Assert::string((string) $marker->error)->contains('needs an expression');
    }

    public function skipWithAnEmptyReasonKeepsTheMarkerAndDropsTheReason(): void
    {
        $marker = (new MarkerParser())->parse('skip:');

        Assert::same($marker->type, MarkerType::Skip);
        Assert::null($marker->skipReason);
    }

    #[DataProvider('anchoredGrammarProvider')]
    public function aMarkerKeywordMustStartTheComment(string $comment): void
    {
        // The patterns are anchored on purpose: prose that merely mentions a
        // keyword is not an assertion.
        Assert::same((new MarkerParser())->parse($comment)->type, MarkerType::None);
    }

    public static function anchoredGrammarProvider(): iterable
    {
        yield 'throws mid-sentence' => ['this throws RuntimeException sometimes'];
        yield 'outputs mid-sentence' => ['it outputs the total'];
        yield 'skip mid-sentence' => ['we skip: this in dev'];
        yield 'arrow mid-sentence' => ['maps a => b'];
    }

    #[DataProvider('trailingContentProvider')]
    public function aMarkerConsumesTheRestOfTheComment(string $comment, MarkerType $expected): void
    {
        Assert::same((new MarkerParser())->parse($comment)->type, $expected);
    }

    public static function trailingContentProvider(): iterable
    {
        yield 'throws with a fqcn' => ['throws \\Rasuvaeff\\DocExec\\Cli\\UsageError', MarkerType::Throws];
        yield 'outputs multiline' => ["outputs first\nsecond", MarkerType::Outputs];
    }

    #[DataProvider('bareKeywordProvider')]
    public function aMarkerKeywordWithNoPayloadIsInvalidRatherThanProse(string $comment, string $expectedError): void
    {
        // Falling back to "not a marker" would fail open: the statement would
        // run with no assertion while its block still reported PASS.
        $marker = (new MarkerParser())->parse($comment);

        Assert::same($marker->type, MarkerType::Invalid);
        Assert::string((string) $marker->error)->contains($expectedError);
    }

    public static function bareKeywordProvider(): iterable
    {
        yield 'bare arrow' => ['=>', 'needs an expression'];
        yield 'bare throws' => ['throws', 'needs an exception class name'];
        yield 'bare throws padded' => ['throws   ', 'needs an exception class name'];
        yield 'bare outputs' => ['outputs', 'needs the expected output'];
        yield 'bare outputs padded' => ['outputs  ', 'needs the expected output'];
    }

    public function surroundingWhitespaceIsIgnored(): void
    {
        $marker = (new MarkerParser())->parse('   => 5   ');

        Assert::same($marker->type, MarkerType::Equals);
        Assert::same($marker->expression, '5');
    }

    public function anExceptionSubstringIsTrimmed(): void
    {
        $marker = (new MarkerParser())->parse('throws RuntimeException |   boom   ');

        Assert::same($marker->exceptionSubstring, 'boom');
    }

    public function aWhitespaceOnlyCommentIsNotAMarker(): void
    {
        Assert::same((new MarkerParser())->parse('   ')->type, MarkerType::None);
    }

    #[DataProvider('unanchoredTailProvider')]
    public function aMarkerMustMatchTheWholeComment(string $comment): void
    {
        // The patterns are anchored at both ends: a trailing fragment means
        // the comment was prose that merely begins like a marker.
        Assert::same((new MarkerParser())->parse($comment)->type, MarkerType::None);
    }

    public static function unanchoredTailProvider(): iterable
    {
        yield 'throws with a second line' => ["throws RuntimeException\nand then recovers"];
        yield 'skip with a second line' => ["skip: later\nbut not now"];
    }

    public function aCommentPaddedWithWhitespaceStillParses(): void
    {
        $marker = (new MarkerParser())->parse("  skip: flaky in CI  ");

        Assert::same($marker->type, MarkerType::Skip);
        Assert::same($marker->skipReason, 'flaky in CI');
    }

    public function anOutputsPayloadKeepsItsInnerSpacingButNotItsEdges(): void
    {
        $marker = (new MarkerParser())->parse('outputs one  two');

        Assert::same($marker->expectedOutput, 'one  two');
    }

    public function throwsRequiresWhitespaceAfterTheKeyword(): void
    {
        Assert::same((new MarkerParser())->parse('throwsRuntimeException')->type, MarkerType::None);
    }

    public function skipRequiresTheColonFormForAReason(): void
    {
        Assert::same((new MarkerParser())->parse('skipping the cache')->type, MarkerType::None);
    }
}
