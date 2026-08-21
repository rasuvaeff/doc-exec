<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Statement;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Token;
use Throwable;

/**
 * Splits a code block's PHP source into top-level statements using a real
 * PHP parser, so every construct the language has — closures, `if`/`else`,
 * `try`/`catch`, `match`, anonymous classes, `do`/`while` — is one statement,
 * exactly as PHP itself sees it.
 *
 * Each statement keeps its verbatim source text (taken back out of the
 * original code by file position, never re-printed), the line it *starts*
 * on, and a trailing `//`/`#`/`/* *\/` comment sitting on the same physical
 * line as its last token. A comment on its own line attaches to nothing.
 *
 * @internal
 */
final readonly class StatementSplitter
{
    private const string PREFIX = "<?php\n";

    /**
     * On its own line, so that it terminates the statement rather than
     * landing inside a trailing `//` comment.
     */
    private const string TERMINATOR = "\n;";

    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    /**
     * @return list<Statement>
     * @throws StatementParseError when the block is not valid PHP
     */
    public function split(string $code): array
    {
        [$nodes, $source, $appendedSemicolon] = $this->parse($code);
        $tokens = array_values($this->parser->getTokens());
        $lastOffset = \strlen($source) - 1;
        $statements = [];

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Nop) {
                continue;
            }

            $start = $node->getStartFilePos();
            $end = $node->getEndFilePos();
            $isSynthetic = $appendedSemicolon && $end === $lastOffset;
            $comment = $isSynthetic
                ? $this->commentBefore($tokens, $node->getEndTokenPos())
                : $this->trailingCommentToken($tokens, $node->getEndTokenPos(), $node->getEndLine());
            $text = substr($source, $start, $end - $start + 1);

            if ($isSynthetic) {
                // The synthetic terminator sits on a line of its own, after
                // any trailing comment; both are cut back off so the
                // statement keeps exactly the text the document had.
                $text = $comment instanceof Token
                    ? rtrim(substr($source, $start, $comment->pos - $start))
                    : rtrim(substr($text, 0, -\strlen(self::TERMINATOR)));
            }

            $statements[] = new Statement(
                code: $text,
                line: max(1, $node->getStartLine() - 1),
                trailingComment: $comment instanceof Token ? $this->stripCommentSyntax($comment->text) : null,
                kind: $this->kindOf($node),
            );
        }

        return $statements;
    }

    /**
     * A block may legitimately end in a bare expression with no `;` —
     * `1 + 1 // => 2` is the shortest doctest there is. PHP itself rejects
     * that, so one retry with a synthetic terminator is made and the
     * terminator is stripped back off the statement's text.
     *
     * @return array{0: list<Stmt>, 1: string, 2: bool} [nodes, parsed source, semicolon appended]
     * @throws StatementParseError
     */
    private function parse(string $code): array
    {
        $source = self::PREFIX . $code;

        try {
            return [$this->parseSource($source), $source, false];
        } catch (Error $error) {
            $retry = $source . self::TERMINATOR;

            try {
                return [$this->parseSource($retry), $retry, true];
            } catch (Throwable) {
                throw new StatementParseError(
                    $error->getRawMessage(),
                    max(1, $error->getStartLine() - 1),
                );
            }
        }
    }

    /**
     * @return list<Stmt>
     * @throws Error
     */
    private function parseSource(string $source): array
    {
        try {
            $nodes = $this->parser->parse($source);
        } catch (Error $error) {
            throw $error;
        } catch (Throwable $failure) {
            // A parser build older than the running PHP rejects newer syntax
            // with its own exception type rather than PhpParser\Error; report
            // it against the block instead of letting a stack trace escape.
            throw new Error($failure->getMessage(), ['startLine' => 1]);
        }

        return array_values($nodes ?? []);
    }

    /**
     * The comment trailing a statement on the same physical line as its last
     * token. A comment on its own line attaches to nothing.
     *
     * @param list<Token> $tokens
     */
    private function trailingCommentToken(array $tokens, int $endTokenPos, int $endLine): ?Token
    {
        $count = \count($tokens);

        for ($i = $endTokenPos + 1; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token->line !== $endLine) {
                return null;
            }

            if ($token->is(\T_WHITESPACE)) {
                continue;
            }

            return $token->is(\T_COMMENT) ? $token : null;
        }

        return null;
    }

    /**
     * The comment before the synthetic terminator: when a block ends in a
     * bare expression, its marker sits *before* the `;` this class added, so
     * the forward scan would look past it.
     *
     * @param list<Token> $tokens
     */
    private function commentBefore(array $tokens, int $endTokenPos): ?Token
    {
        for ($i = $endTokenPos - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if ($token->is(\T_WHITESPACE)) {
                continue;
            }

            return $token->is(\T_COMMENT) ? $token : null;
        }

        return null;
    }

    private function stripCommentSyntax(string $comment): string
    {
        return trim((string) preg_replace('~^//|^#|^/\*|\*/$~', '', $comment));
    }

    private function kindOf(Stmt $node): StatementKind
    {
        return match (true) {
            $node instanceof Stmt\Expression => StatementKind::Expression,
            $node instanceof Stmt\Use_, $node instanceof Stmt\GroupUse => StatementKind::Import,
            default => StatementKind::Other,
        };
    }
}
