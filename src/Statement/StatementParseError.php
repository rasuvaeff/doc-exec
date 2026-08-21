<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

use RuntimeException;

/**
 * A code block that is not valid PHP. Carries the line **relative to the
 * block** so the failure can be reported against the document rather than
 * against the generated temporary script.
 *
 * @api
 */
final class StatementParseError extends RuntimeException
{
    public function __construct(
        string $message,
        public int $blockLine,
    ) {
        parent::__construct($message);
    }
}
