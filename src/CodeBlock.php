<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * One fenced `php doc-exec` block: where it came from, its position in the
 * document, its source, and the scope group it shares variables with.
 *
 * @api
 */
final readonly class CodeBlock
{
    /**
     * @param non-empty-string $file
     * @param int<0, max> $ordinal position among the executable blocks of the document
     * @param positive-int $startLine first line of the code, not of the fence
     */
    public function __construct(
        public string $file,
        public int $ordinal,
        public int $startLine,
        public string $code,
        public string $scopeKey,
    ) {}
}
