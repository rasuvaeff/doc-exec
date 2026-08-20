<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * @api
 */
final readonly class CodeBlock
{
    public function __construct(
        public string $file,
        public int $ordinal,
        public int $startLine,
        public string $code,
        public string $scopeKey,
    ) {}
}
