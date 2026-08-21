<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Marker;

/**
 * A parsed trailing-comment marker. Construct through the named factories:
 * every one of them corresponds to exactly one {@see MarkerType} case.
 *
 * @api
 */
final readonly class ParsedMarker
{
    private function __construct(
        public MarkerType $type,
        public ?string $expression = null,
        public ?string $exceptionClass = null,
        public ?string $exceptionSubstring = null,
        public ?string $expectedOutput = null,
        public ?string $skipReason = null,
        public ?string $error = null,
    ) {}

    public static function none(): self
    {
        return new self(type: MarkerType::None);
    }

    public static function equals(string $expression): self
    {
        return new self(type: MarkerType::Equals, expression: $expression);
    }

    public static function throws(string $exceptionClass, ?string $substring): self
    {
        return new self(
            type: MarkerType::Throws,
            exceptionClass: $exceptionClass,
            exceptionSubstring: $substring,
        );
    }

    public static function outputs(string $expectedOutput): self
    {
        return new self(type: MarkerType::Outputs, expectedOutput: $expectedOutput);
    }

    public static function skip(?string $reason): self
    {
        return new self(type: MarkerType::Skip, skipReason: $reason);
    }

    public static function invalid(string $error): self
    {
        return new self(type: MarkerType::Invalid, error: $error);
    }
}
