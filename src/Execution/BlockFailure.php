<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

use Rasuvaeff\DocExec\CodeBlock;

/**
 * A block that could not even be turned into runnable code — it is not valid
 * PHP. It is reported on its own instead of being written into the shared
 * script, where a compile error would take down every other block in the
 * same scope group.
 *
 * @internal
 */
final readonly class BlockFailure
{
    public function __construct(
        public CodeBlock $block,
        public string $message,
    ) {}
}
