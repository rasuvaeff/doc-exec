<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

use Rasuvaeff\DocExec\Marker\ParsedMarker;
use Rasuvaeff\DocExec\Statement\Statement;

/**
 * @api
 */
final readonly class StatementResult
{
    public function __construct(
        public Statement $statement,
        public ParsedMarker $marker,
        public StatementOutcome $outcome,
        public ?string $message,
    ) {}
}
