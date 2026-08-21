<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Cli;

/**
 * Parsed `bin/doc-exec` command line. Unknown flags are rejected instead of
 * being read as file names — a typo'd `--bootstap=…` used to be opened as a
 * document, and `--help` was reported as an unreadable file.
 *
 * @api
 */
final readonly class Arguments
{
    /**
     * @param list<non-empty-string> $files
     * @param positive-int $timeoutSeconds
     */
    private function __construct(
        public array $files,
        public ?string $bootstrap,
        public int $timeoutSeconds,
        public bool $wantsHelp,
    ) {}

    /**
     * @param list<string> $arguments
     * @throws UsageError
     */
    public static function parse(array $arguments, string $workingDirectory): self
    {
        $bootstrap = null;
        $timeoutSeconds = 30;
        /** @var list<non-empty-string> $files */
        $files = [];

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                return new self(files: [], bootstrap: null, timeoutSeconds: $timeoutSeconds, wantsHelp: true);
            }

            if (str_starts_with($argument, '--bootstrap=')) {
                $bootstrap = self::readableFile(substr($argument, \strlen('--bootstrap=')), 'bootstrap');

                continue;
            }

            if (str_starts_with($argument, '--timeout=')) {
                $timeoutSeconds = self::positiveInt(substr($argument, \strlen('--timeout=')));

                continue;
            }

            if (str_starts_with($argument, '-')) {
                throw new UsageError(\sprintf('unknown option "%s"', $argument));
            }

            $files[] = self::readableFile($argument, 'document');
        }

        if ($files === []) {
            $files = [self::readableFile(rtrim($workingDirectory, '/') . '/README.md', 'document')];
        }

        return new self(files: $files, bootstrap: $bootstrap, timeoutSeconds: $timeoutSeconds, wantsHelp: false);
    }

    public static function usage(): string
    {
        return <<<TXT
            Usage: doc-exec [options] [file.md ...]

              --bootstrap=<file>  autoloader to require in the child process
                                  (default: the nearest vendor/autoload.php)
              --timeout=<seconds> wall-clock budget per scope group (default: 30)
              --help, -h          show this message

            With no file argument, README.md in the current directory is checked.
            Exit code 0 means every executed block passed.
            TXT;
    }

    /**
     * @return non-empty-string
     * @throws UsageError
     */
    private static function readableFile(string $path, string $what): string
    {
        if ($path === '') {
            throw new UsageError(\sprintf('empty %s path', $what));
        }

        if (!is_file($path)) {
            throw new UsageError(\sprintf('%s "%s" does not exist', $what, $path));
        }

        return $path;
    }

    /**
     * @return positive-int
     * @throws UsageError
     */
    private static function positiveInt(string $value): int
    {
        $seconds = preg_match('/^[0-9]+\z/', $value) === 1 ? (int) $value : 0;

        if ($seconds < 1) {
            throw new UsageError(\sprintf('--timeout expects a positive whole number of seconds, got "%s"', $value));
        }

        return $seconds;
    }
}
