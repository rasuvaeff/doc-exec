<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

/**
 * What kind of top-level statement a {@see Statement} holds. Drives two
 * decisions: `use` declarations are emitted verbatim (PHP allows imports
 * only at file top level), and the `// =>` marker is only meaningful on an
 * expression statement, because that is the only kind that has a value.
 *
 * @api
 */
enum StatementKind
{
    case Expression;
    case Import;
    case Other;
}
