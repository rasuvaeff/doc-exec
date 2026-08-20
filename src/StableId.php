<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * `sha256(file + blockOrdinal + normalize(code))`. Editing a block's code
 * yields a new id on purpose — a changed example is a different example,
 * not a continuation of the old one's history.
 *
 * @api
 */
final readonly class StableId
{
    public static function compute(string $file, int $blockOrdinal, string $code): string
    {
        return hash('sha256', $file . '#' . $blockOrdinal . '#' . self::normalize($code));
    }

    private static function normalize(string $code): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $code));
    }
}
