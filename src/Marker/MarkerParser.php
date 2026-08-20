<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Marker;

/**
 * Parses a statement's trailing comment into a doc-exec marker.
 *
 * Grammar: `=> <expr>`, `throws <FQCN>[ | <substring>]`, `outputs <text>`,
 * `skip[: <reason>]`. Anything else (a plain human comment) parses as
 * {@see MarkerType::None} — it is not an error, just not a marker.
 *
 * @api
 */
final readonly class MarkerParser
{
    public function parse(?string $comment): ParsedMarker
    {
        if ($comment === null || trim($comment) === '') {
            return ParsedMarker::none();
        }

        $comment = trim($comment);

        if (str_starts_with($comment, '=>')) {
            return ParsedMarker::equals(trim(substr($comment, 2)));
        }

        if (preg_match('/^throws\s+(\S+)(?:\s*\|\s*(.+))?$/', $comment, $matches) === 1) {
            return ParsedMarker::throws($matches[1], isset($matches[2]) ? trim($matches[2]) : null);
        }

        if (preg_match('/^outputs\s+(.+)$/s', $comment, $matches) === 1) {
            return ParsedMarker::outputs($matches[1]);
        }

        if (preg_match('/^skip\b\s*:?\s*(.*)$/', $comment, $matches) === 1) {
            return ParsedMarker::skip($matches[1] !== '' ? $matches[1] : null);
        }

        return ParsedMarker::none();
    }
}
