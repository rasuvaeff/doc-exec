<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

/**
 * Extracts executable fenced code blocks from a Markdown document.
 *
 * A block runs only when opt-in: its info string is `php doc-exec`. A bare
 * `php` fence is illustrative-only and ignored, unless `$strict` is enabled.
 * Blocks are grouped into scopes by the nearest preceding heading (ATX or
 * setext, any level) — consecutive blocks under one heading share variables
 * in the order they appear; a new heading starts a fresh scope.
 *
 * Fences carry their indentation: a block nested in a list item or a
 * blockquote is extracted, and the opening fence's indentation is stripped
 * from its lines so the code is still valid PHP.
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
        $fenceIndent = 0;
        $fenceInfo = '';
        $fenceStartLine = 0;
        /** @var list<string> $fenceLines */
        $fenceLines = [];
        $previousLine = '';

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            if (!$inFence) {
                if (preg_match('/^ {0,3}#{1,6}\s+(.+)$/', $line, $matches) === 1) {
                    $scopeKey = $lineNumber . ':' . trim($matches[1]);
                    $previousLine = $line;

                    continue;
                }

                // Setext heading: the underline belongs to the line above it,
                // which is why the previous line has to be remembered.
                if (trim($previousLine) !== '' && preg_match('/^ {0,3}(=+|-{2,})\s*$/', $line) === 1) {
                    $scopeKey = $lineNumber . ':' . trim($previousLine);
                    $previousLine = $line;

                    continue;
                }

                if (preg_match('/^(\s*)(`{3,}|~{3,})\s*(.*)$/', $line, $matches) === 1) {
                    $inFence = true;
                    $fenceIndent = \strlen($matches[1]);
                    $fenceChar = $matches[2][0];
                    $fenceLength = \strlen($matches[2]);
                    $fenceInfo = trim($matches[3]);
                    $fenceStartLine = $lineNumber + 1;
                    $fenceLines = [];
                }

                $previousLine = $line;

                continue;
            }

            if ($this->isClosingFence($line, $fenceChar, $fenceLength)) {
                $inFence = false;
                $previousLine = $line;

                if ($this->isExecutable($fenceInfo)) {
                    $blocks[] = $this->makeBlock($file, $ordinal, $fenceStartLine, $fenceLines, $fenceIndent, $scopeKey);
                    ++$ordinal;
                }

                continue;
            }

            $fenceLines[] = $line;
        }

        // A fence left open at EOF is a typo in the document, not a reason to
        // drop the block: extracting it means the reader sees a real result
        // instead of a block that silently never ran.
        if ($inFence && $this->isExecutable($fenceInfo)) {
            $blocks[] = $this->makeBlock($file, $ordinal, $fenceStartLine, $fenceLines, $fenceIndent, $scopeKey);
        }

        return $blocks;
    }

    /**
     * @param list<string> $fenceLines
     */
    private function makeBlock(
        string $file,
        int $ordinal,
        int $startLine,
        array $fenceLines,
        int $indent,
        string $scopeKey,
    ): CodeBlock {
        $code = implode("\n", array_map(
            static fn(string $line): string => $indent === 0 ? $line : self::dedent($line, $indent),
            $fenceLines,
        ));

        return new CodeBlock(
            file: $file,
            ordinal: $ordinal,
            startLine: $startLine,
            code: $code,
            scopeKey: $scopeKey,
        );
    }

    private static function dedent(string $line, int $indent): string
    {
        $stripped = 0;

        while ($stripped < $indent && ($line[$stripped] ?? '') === ' ') {
            ++$stripped;
        }

        return substr($line, $stripped);
    }

    private function isClosingFence(string $line, string $fenceChar, int $fenceLength): bool
    {
        if (preg_match('/^\s*(`{3,}|~{3,})\s*$/', $line, $matches) !== 1) {
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
