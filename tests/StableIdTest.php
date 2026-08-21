<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\StableId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StableId::class)]
final class StableIdTest
{
    public function isDeterministicForTheSameInputs(): void
    {
        $a = StableId::compute('README.md', 0, '$x = 1;');
        $b = StableId::compute('README.md', 0, '$x = 1;');

        Assert::same($a, $b);
    }

    public function ignoresWhitespaceDifferences(): void
    {
        $a = StableId::compute('README.md', 0, '$x = 1;');
        $b = StableId::compute('README.md', 0, "\$x   =\n1;\n");

        Assert::same($a, $b);
    }

    public function changesWhenCodeChanges(): void
    {
        $a = StableId::compute('README.md', 0, '$x = 1;');
        $b = StableId::compute('README.md', 0, '$x = 2;');

        Assert::false($a === $b);
    }

    public function changesWhenOrdinalChanges(): void
    {
        $a = StableId::compute('README.md', 0, '$x = 1;');
        $b = StableId::compute('README.md', 1, '$x = 1;');

        Assert::false($a === $b);
    }

    public function changesWhenFileChanges(): void
    {
        $a = StableId::compute('README.md', 0, '$x = 1;');
        $b = StableId::compute('docs/guide.md', 0, '$x = 1;');

        Assert::false($a === $b);
    }

    public function theOrdinalIsPartOfTheIdAndCannotBeConfusedWithTheFileName(): void
    {
        // Without the separators, ('a#1', 0) and ('a', 1) would hash the same
        // string and two different blocks would share one history.
        Assert::true(StableId::compute('a#1', 0, 'x') !== StableId::compute('a', 1, 'x'));
    }

    public function theFileIsPartOfTheId(): void
    {
        Assert::true(StableId::compute('a.md', 0, 'x') !== StableId::compute('b.md', 0, 'x'));
    }

    public function theCodeIsPartOfTheId(): void
    {
        Assert::true(StableId::compute('a.md', 0, 'x') !== StableId::compute('a.md', 0, 'y'));
    }

    public function theIdIsASha256Digest(): void
    {
        Assert::same(StableId::compute('a.md', 0, 'x'), hash('sha256', 'a.md#0#x'));
    }
}
