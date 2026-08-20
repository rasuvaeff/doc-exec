<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * Finds `vendor/autoload.php` by walking up from a starting directory.
 * Used only when no explicit `--bootstrap` is given.
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

            $parent = \dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }
}
