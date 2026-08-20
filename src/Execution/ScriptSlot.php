<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\Marker\ParsedMarker;
use Rasuvaeff\DocExec\Statement\Statement;

/**
 * @internal
 */
final readonly class ScriptSlot
{
    public function __construct(
        public CodeBlock $block,
        public Statement $statement,
        public ParsedMarker $marker,
    ) {}
}
