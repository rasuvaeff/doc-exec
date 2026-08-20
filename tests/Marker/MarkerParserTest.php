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
        yield 'skip with space reason' => ['skip network call', 'network call'];
    }
}
