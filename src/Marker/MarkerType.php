<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Marker;

/**
 * @api
 */
enum MarkerType
{
    case None;
    case Equals;
    case Throws;
    case Outputs;
    case Skip;
}
