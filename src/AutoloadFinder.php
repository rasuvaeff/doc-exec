<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * Finds `vendor/autoload.php` by walking up from a starting directory,
 * stopping at the project boundary — the first directory holding a
 * `composer.json` or a `.git`. Without that stop the walk runs to the
 * filesystem root and can bootstrap an unrelated parent project's
 * autoloader, which fails in ways that look like the document's fault.
 *
 * Used only when no explicit bootstrap is given.
 *
 * @api
 */
final readonly class AutoloadFinder
{
    public function find(string $startDir): ?string
    {
        $dir = rtrim($startDir, '/');

        if ($dir === '') {
            $dir = '/';
        }

        while (true) {
            $candidate = $dir . '/vendor/autoload.php';

            if (is_file($candidate)) {
                return $candidate;
            }

            if ($this->isProjectRoot($dir)) {
                return null;
            }

            $parent = \dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    private function isProjectRoot(string $dir): bool
    {
        return is_file($dir . '/composer.json') || is_dir($dir . '/.git');
    }
}
