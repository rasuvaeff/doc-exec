<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

/**
 * @api
 */
final readonly class Statement
{
    public function __construct(
        public string $code,
        public int $line,
        public ?string $trailingComment,
    ) {}
}
