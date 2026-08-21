<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

/**
 * @internal
 */
final readonly class GeneratedScript
{
    /**
     * @param list<ScriptSlot> $slots
     * @param list<BlockFailure> $blockFailures blocks rejected before execution
     */
    public function __construct(
        public string $source,
        public array $slots,
        public array $blockFailures = [],
    ) {}
}
