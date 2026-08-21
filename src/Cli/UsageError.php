<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Cli;

use RuntimeException;

/**
 * The command line could not be understood: an unknown flag, a bad value,
 * or a file that is not there. Distinct from a failing document, which is a
 * result rather than a usage mistake.
 *
 * @api
 */
final class UsageError extends RuntimeException {}
