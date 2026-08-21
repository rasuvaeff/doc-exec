<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Tests\Cli;

use Rasuvaeff\DocExec\Cli\Arguments;
use Rasuvaeff\DocExec\Cli\UsageError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Arguments::class)]
final class ArgumentsTest
{
    private string $directory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/doc-exec-args-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/README.md', "# Title\n");
        file_put_contents($this->directory . '/other.md', "# Other\n");
        file_put_contents($this->directory . '/autoload.php', "<?php\n");
    }

    #[AfterTest]
    public function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function noArgumentsChecksReadmeInTheWorkingDirectory(): void
    {
        $arguments = Arguments::parse([], $this->directory);

        Assert::same($arguments->files, [$this->directory . '/README.md']);
        Assert::null($arguments->bootstrap);
        Assert::same($arguments->timeoutSeconds, 30);
        Assert::false($arguments->wantsHelp);
    }

    public function filesAreTakenInTheOrderGiven(): void
    {
        $arguments = Arguments::parse([$this->directory . '/other.md', $this->directory . '/README.md'], '/nowhere');

        Assert::same($arguments->files, [$this->directory . '/other.md', $this->directory . '/README.md']);
    }

    public function bootstrapAndTimeoutAreParsed(): void
    {
        $arguments = Arguments::parse(
            ['--bootstrap=' . $this->directory . '/autoload.php', '--timeout=5', $this->directory . '/other.md'],
            '/nowhere',
        );

        Assert::same($arguments->bootstrap, $this->directory . '/autoload.php');
        Assert::same($arguments->timeoutSeconds, 5);
    }

    public function helpIsAnswerableWithoutAnyDocument(): void
    {
        $arguments = Arguments::parse(['--help'], '/nowhere');

        Assert::true($arguments->wantsHelp);
        Assert::same($arguments->files, []);
    }

    public function shortHelpFlagIsAccepted(): void
    {
        Assert::true(Arguments::parse(['-h'], '/nowhere')->wantsHelp);
    }

    public function aTypoedFlagIsRejectedInsteadOfBeingReadAsAFile(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('unknown option "--bootstap=x"');

        Arguments::parse(['--bootstap=x'], '/nowhere');
    }

    public function aMissingDocumentIsNamedRatherThanWarnedAbout(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('document "/nope.md" does not exist');

        Arguments::parse(['/nope.md'], '/nowhere');
    }

    public function aMissingBootstrapIsRejected(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('bootstrap');

        Arguments::parse(['--bootstrap=/nope/autoload.php'], '/nowhere');
    }

    public function aMissingReadmeIsRejected(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('README.md');

        Arguments::parse([], '/nowhere');
    }

    public function aNonNumericTimeoutIsRejected(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('positive whole number');

        Arguments::parse(['--timeout=soon'], $this->directory);
    }

    public function aZeroTimeoutIsRejected(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('positive whole number');

        Arguments::parse(['--timeout=0'], $this->directory);
    }

    public function usageMentionsEveryOption(): void
    {
        $usage = Arguments::usage();

        Assert::string($usage)->contains('--bootstrap=');
        Assert::string($usage)->contains('--timeout=');
        Assert::string($usage)->contains('--help');
    }

    #[DataProvider('workingDirectorySuffixProvider')]
    public function aTrailingSeparatorOnTheWorkingDirectoryDoesNotDoubleUp(string $suffix): void
    {
        $arguments = Arguments::parse([], $this->directory . $suffix);

        Assert::same($arguments->files, [$this->directory . '/README.md']);
    }

    public static function workingDirectorySuffixProvider(): iterable
    {
        yield 'none' => [''];
        yield 'forward slash' => ['/'];
        yield 'backslash' => ['\\'];
    }

    #[DataProvider('rejectedTimeoutProvider')]
    public function malformedTimeoutValuesAreRejected(string $value): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('positive whole number');

        Arguments::parse(['--timeout=' . $value], $this->directory);
    }

    public static function rejectedTimeoutProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'signed positive' => ['+5'];
        yield 'fractional' => ['1.5'];
        yield 'scientific' => ['1e3'];
        yield 'trailing text' => ['5s'];
        yield 'leading text' => ['s5'];
        yield 'leading newline' => ["\n5"];
        yield 'trailing newline' => ["5\n"];
    }

    public function oneSecondIsTheSmallestAcceptedTimeout(): void
    {
        Assert::same(Arguments::parse(['--timeout=1'], $this->directory)->timeoutSeconds, 1);
    }

    public function aLargeTimeoutIsAccepted(): void
    {
        Assert::same(Arguments::parse(['--timeout=3600'], $this->directory)->timeoutSeconds, 3600);
    }

    public function anEmptyBootstrapValueIsRejected(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('empty bootstrap path');

        Arguments::parse(['--bootstrap='], $this->directory);
    }

    public function aDirectoryIsNotADocument(): void
    {
        Expect::exception(UsageError::class)->withMessageContaining('does not exist');

        Arguments::parse([$this->directory], '/nowhere');
    }
}
