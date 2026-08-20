<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests;

use Rasuvaeff\DocExec\AutoloadFinder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(AutoloadFinder::class)]
final class AutoloadFinderTest
{
    private string $root;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/doc-exec-autoload-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor', recursive: true);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");
        mkdir($this->root . '/nested/deep', recursive: true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        @unlink($this->root . '/vendor/autoload.php');
        @rmdir($this->root . '/vendor');
        @rmdir($this->root . '/nested/deep');
        @rmdir($this->root . '/nested');
        @rmdir($this->root);
    }

    public function findsAutoloadInTheStartDirectory(): void
    {
        $found = (new AutoloadFinder())->find($this->root);

        Assert::same($found, $this->root . '/vendor/autoload.php');
    }

    public function findsAutoloadByWalkingUpFromANestedDirectory(): void
    {
        $found = (new AutoloadFinder())->find($this->root . '/nested/deep');

        Assert::same($found, $this->root . '/vendor/autoload.php');
    }

    public function returnsNullWhenNothingIsFound(): void
    {
        $isolated = sys_get_temp_dir() . '/doc-exec-no-vendor-' . bin2hex(random_bytes(8));
        mkdir($isolated, recursive: true);

        try {
            $found = (new AutoloadFinder())->find($isolated);

            Assert::null($found);
        } finally {
            @rmdir($isolated);
        }
    }
}
