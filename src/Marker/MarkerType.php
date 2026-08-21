<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Marker;

/**
 * The kind of assertion a statement's trailing comment carries.
 * {@see MarkerType::None} means the comment is prose, not a marker;
 * {@see MarkerType::Invalid} means it looks like a marker but its payload
 * is unusable, and the statement fails with a diagnostic instead of being
 * turned into broken generated code.
 *
 * @api
 */
enum MarkerType
{
    case None;
    case Equals;
    case Throws;
    case Outputs;
    case Skip;
    case Invalid;
}
