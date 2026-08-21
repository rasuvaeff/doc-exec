<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

/**
 * What kind of top-level statement a {@see Statement} holds. Drives three
 * decisions: `use` and `const` are emitted verbatim because PHP accepts them
 * only at the top level of a file; a file-level `declare` is dropped, since
 * the generated script sets its own before anything else; and the `// =>`
 * marker is only meaningful on an expression statement, because that is the
 * only kind that has a value.
 *
 * @api
 */
enum StatementKind
{
    case Expression;

    /** `use X;`, `use function f;`, `use const C;`, `use X\{A, B};` */
    case Import;

    /** `const NAME = …;` — accepted only at the top level of a file. */
    case Constant;

    /** `declare(strict_types=…)`/`declare(encoding=…)` — file-level only. */
    case FileDeclaration;

    case Other;
}
