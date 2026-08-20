<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * @api
 */
final readonly class DocumentResult
{
    /**
     * @param list<BlockResult> $blocks
     */
    public function __construct(
        public string $file,
        public array $blocks,
    ) {}

    public function passed(): bool
    {
        foreach ($this->blocks as $block) {
            if (!$block->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function failedIds(): array
    {
        return array_values(array_map(
            static fn(BlockResult $block): string => $block->stableId,
            array_filter($this->blocks, static fn(BlockResult $block): bool => !$block->passed),
        ));
    }
}
