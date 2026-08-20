<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

use PhpToken;

/**
 * Splits a code block's PHP source into top-level statements, using the
 * tokenizer (not naive line-splitting) so a multi-line `foreach`/`if`/array
 * literal is kept together as one statement. A statement boundary is a `;`
 * or a `}` closing block syntax, both only recognised at bracket depth 0.
 *
 * A trailing `//`/`#`/`/* *\/` comment on the SAME physical line as the
 * boundary token is captured as the statement's marker comment; a comment on
 * its own line does not attach to anything.
 *
 * @api
 */
final readonly class StatementSplitter
{
    private const array OPENERS = ['{', '(', '['];
    private const array CLOSERS = ['}', ')', ']'];

    /**
     * @return list<Statement>
     */
    public function split(string $code): array
    {
        $tokens = PhpToken::tokenize('<?php ' . $code);
        $statements = [];

        /** @var list<string> $stack */
        $stack = [];
        /** @var list<PhpToken> $chunk */
        $chunk = [];

        $count = \count($tokens);
        $i = 1; // skip the synthetic T_OPEN_TAG token

        while ($i < $count) {
            $token = $tokens[$i];
            $text = $token->text;
            $opening = null;

            if (\in_array($text, self::OPENERS, true)) {
                $stack[] = $text;
            } elseif (\in_array($text, self::CLOSERS, true)) {
                $opening = array_pop($stack);
            }

            $chunk[] = $token;

            $isBoundary = $stack === [] && ($text === ';' || ($text === '}' && $opening === '{'));

            if ($isBoundary) {
                [$comment, $consumed] = $this->readTrailingComment($tokens, $i + 1, $token->line);
                $statements[] = $this->buildStatement($chunk, $token->line, $comment);
                $chunk = [];
                $i += 1 + $consumed;

                continue;
            }

            ++$i;
        }

        $tail = trim(implode('', array_map(static fn(PhpToken $t): string => $t->text, $chunk)));
        $lastToken = end($chunk);

        if ($tail !== '' && $lastToken instanceof PhpToken && $this->containsCode($chunk)) {
            $statements[] = new Statement(code: $tail, line: $lastToken->line, trailingComment: null);
        }

        return $statements;
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array{0: ?string, 1: int} [comment text or null, tokens consumed]
     */
    private function readTrailingComment(array $tokens, int $start, int $boundaryLine): array
    {
        $consumed = 0;
        $count = \count($tokens);

        for ($i = $start; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token->line !== $boundaryLine) {
                break;
            }

            if ($token->is(\T_WHITESPACE)) {
                ++$consumed;

                continue;
            }

            if ($token->is(\T_COMMENT)) {
                $comment = trim((string) preg_replace('~^//|^#|^/\*|\*/$~', '', $token->text));

                return [$comment, $consumed + 1];
            }

            break;
        }

        return [null, $consumed];
    }

    /**
     * @param list<PhpToken> $chunk
     */
    private function containsCode(array $chunk): bool
    {
        foreach ($chunk as $token) {
            if (!$token->is(\T_WHITESPACE) && !$token->is(\T_COMMENT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<PhpToken> $chunk
     */
    private function buildStatement(array $chunk, int $line, ?string $comment): Statement
    {
        $code = trim(implode('', array_map(static fn(PhpToken $t): string => $t->text, $chunk)));

        return new Statement(code: $code, line: $line, trailingComment: $comment);
    }
}
