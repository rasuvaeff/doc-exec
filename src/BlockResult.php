<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * @api
 */
final readonly class BlockResult
{
    /**
     * @param list<StatementResult> $statements
     */
    public function __construct(
        public CodeBlock $block,
        public string $stableId,
        public array $statements,
        public bool $passed,
        public ?string $processError = null,
    ) {}
}
