<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Marker;

/**
 * Parses a statement's trailing comment into a doc-exec marker.
 *
 * Grammar: `=> <expr>`, `throws <FQCN>[ | <substring>]`, `outputs <text>`,
 * `skip` or `skip: <reason>`. Anything else (a plain human comment) parses
 * as {@see MarkerType::None} — it is not an error, just not a marker.
 *
 * The grammar is deliberately strict on `skip`: `// skip this in production`
 * is prose and stays executable. A lenient `skip <anything>` rule fails open
 * — the statement silently never runs while its block still reports PASS.
 *
 * A marker whose payload is empty (`// =>`) parses as
 * {@see MarkerType::Invalid} rather than being passed on to become a syntax
 * error inside the generated script.
 *
 * @internal
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
            $expression = trim(substr($comment, 2));

            return $expression === ''
                ? ParsedMarker::invalid('the `// =>` marker needs an expression to compare against')
                : ParsedMarker::equals($expression);
        }

        if (preg_match('/^throws\s+(\S+)(?:\s*\|\s*(.+))?$/', $comment, $matches) === 1) {
            return ParsedMarker::throws($matches[1], isset($matches[2]) ? trim($matches[2]) : null);
        }

        if (preg_match('/^outputs\s+(.+)$/s', $comment, $matches) === 1) {
            return ParsedMarker::outputs($matches[1]);
        }

        if ($comment === 'skip') {
            return ParsedMarker::skip(null);
        }

        if (preg_match('/^skip:\s*(.*)$/', $comment, $matches) === 1) {
            $reason = trim($matches[1]);

            return ParsedMarker::skip($reason !== '' ? $reason : null);
        }

        return ParsedMarker::none();
    }
}
