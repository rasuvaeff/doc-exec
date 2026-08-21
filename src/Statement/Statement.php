<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

/**
 * One top-level statement of a code block: its verbatim source, the line it
 * starts on (1-based, relative to the block), the trailing comment that may
 * carry a marker, and what kind of statement PHP considers it.
 *
 * @api
 */
final readonly class Statement
{
    /**
     * @param positive-int $line
     */
    public function __construct(
        public string $code,
        public int $line,
        public ?string $trailingComment,
        public StatementKind $kind = StatementKind::Other,
    ) {}
}
