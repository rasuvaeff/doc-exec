<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * @api
 */
enum StatementOutcome
{
    case Pass;
    case Fail;
    case Skip;
}
