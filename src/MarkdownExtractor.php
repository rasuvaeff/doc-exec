<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * Extracts executable fenced code blocks from a Markdown document.
 *
 * A block runs only when opt-in: its info string is `php doc-exec`. A bare
 * `php` fence is illustrative-only and ignored, unless `$strict` is enabled.
 * Blocks are grouped into scopes by the nearest preceding heading (any
 * level) — consecutive blocks under one heading share variables in the
 * order they appear; a new heading starts a fresh scope.
 *
 * @api
 */
final readonly class MarkdownExtractor
{
    public function __construct(
        private bool $strict = false,
    ) {}

    /**
     * @return list<CodeBlock>
     */
    public function extract(string $markdown, string $file): array
    {
        $lines = explode("\n", $markdown);
        $blocks = [];
        $scopeKey = '';
        $ordinal = 0;

        $inFence = false;
        $fenceChar = '';
        $fenceLength = 0;
        $fenceInfo = '';
        $fenceStartLine = 0;
        /** @var list<string> $fenceLines */
        $fenceLines = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            if (!$inFence) {
                if (preg_match('/^#{1,6}\s+(.+)$/', $line, $matches) === 1) {
                    $scopeKey = $lineNumber . ':' . trim($matches[1]);

                    continue;
                }

                if (preg_match('/^(`{3,}|~{3,})\s*(.*)$/', $line, $matches) === 1) {
                    $inFence = true;
                    $fenceChar = $matches[1][0];
                    $fenceLength = \strlen($matches[1]);
                    $fenceInfo = trim($matches[2]);
                    $fenceStartLine = $lineNumber + 1;
                    $fenceLines = [];
                }

                continue;
            }

            if ($this->isClosingFence($line, $fenceChar, $fenceLength)) {
                $inFence = false;

                if ($this->isExecutable($fenceInfo)) {
                    $blocks[] = new CodeBlock(
                        file: $file,
                        ordinal: $ordinal,
                        startLine: $fenceStartLine,
                        code: implode("\n", $fenceLines),
                        scopeKey: $scopeKey,
                    );
                    ++$ordinal;
                }

                continue;
            }

            $fenceLines[] = $line;
        }

        return $blocks;
    }

    private function isClosingFence(string $line, string $fenceChar, int $fenceLength): bool
    {
        if (preg_match('/^(`{3,}|~{3,})\s*$/', $line, $matches) !== 1) {
            return false;
        }

        return $matches[1][0] === $fenceChar && \strlen($matches[1]) >= $fenceLength;
    }

    private function isExecutable(string $info): bool
    {
        $normalized = strtolower(trim($info));

        if ($normalized === 'php doc-exec') {
            return true;
        }

        return $this->strict && $normalized === 'php';
    }
}
