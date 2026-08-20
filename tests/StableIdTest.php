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
}
