<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\Marker\MarkerParser;
use Rasuvaeff\DocExec\Marker\MarkerType;
use Rasuvaeff\DocExec\Marker\ParsedMarker;
use Rasuvaeff\DocExec\Statement\Statement;
use Rasuvaeff\DocExec\Statement\StatementKind;
use Rasuvaeff\DocExec\Statement\StatementParseError;
use Rasuvaeff\DocExec\Statement\StatementSplitter;

/**
 * Turns one scope group (an ordered list of {@see CodeBlock}s that share
 * variables) into a single, self-contained PHP script. Each top-level
 * statement is wrapped individually so its outcome (output produced,
 * thrown exception, evaluated expression) can be reported back without
 * ever running doc-exec's own tool code in-process — the generated script
 * is only ever executed by a child `php` process, never `eval`'d here.
 *
 * @internal
 */
final readonly class ScriptBuilder
{
    private StatementSplitter $splitter;
    private MarkerParser $markerParser;

    public function __construct(
        ?StatementSplitter $splitter = null,
        ?MarkerParser $markerParser = null,
    ) {
        $this->splitter = $splitter ?? new StatementSplitter();
        $this->markerParser = $markerParser ?? new MarkerParser();
    }

    /**
     * @param list<CodeBlock> $blocks one scope group, in document order
     */
    public function build(array $blocks, string $bootstrap): GeneratedScript
    {
        $slots = [];
        $body = [];
        $blockFailures = [];

        foreach ($blocks as $block) {
            try {
                $statements = $this->splitter->split($block->code);
            } catch (StatementParseError $error) {
                // Reported per block: writing unparsable code into the shared
                // script would turn one broken example into a compile error
                // that fails every block in the scope group.
                $blockFailures[] = new BlockFailure(
                    block: $block,
                    message: \sprintf(
                        'parse error on line %d of the block: %s',
                        $error->blockLine,
                        $error->getMessage(),
                    ),
                );

                continue;
            }

            foreach ($statements as $statement) {
                // `use <Ns>\<Class>;` and `const NAME = …;` are compile-time
                // declarations: PHP only allows them at the top level of a
                // file, never inside a block. Wrapping either in try/catch
                // like every other statement would be a syntax error, so they
                // are emitted verbatim and carry no marker.
                if ($statement->kind === StatementKind::Import || $statement->kind === StatementKind::Constant) {
                    $body[] = $statement->code . "\n";

                    continue;
                }

                // A document's own `declare(strict_types=…)` cannot be
                // emitted at all: the generated script already declares its
                // own, and PHP requires that to be the very first statement
                // of the file.
                if ($statement->kind === StatementKind::FileDeclaration) {
                    continue;
                }

                $marker = $this->validateMarker($this->markerParser->parse($statement->trailingComment), $statement);
                $slot = \count($slots);
                $slots[] = new ScriptSlot(block: $block, statement: $statement, marker: $marker);
                $body[] = $this->renderSlot($slot, $statement, $marker);
            }
        }

        $source = "<?php\n\ndeclare(strict_types=1);\n\n"
            // The results path arrives as argv[1]: a dedicated file
            // descriptor would have been unreadable by the child on Windows,
            // and a doctest runner has no business being POSIX-only. A file
            // still keeps outcomes out of the document's own stdout, and is
            // not bounded by a pipe buffer.
            . "\$__docexec_results_file = \$argv[1];\n"
            . 'require_once ' . $this->literal($bootstrap) . ";\n\n"
            . "\$__docexec_results = [];\n\n"
            . implode("\n", $body)
            . "\nfile_put_contents(\$__docexec_results_file, "
            . "json_encode(\$__docexec_results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));\n";

        return new GeneratedScript(source: $source, slots: $slots, blockFailures: $blockFailures);
    }

    /**
     * `// =>` compares the *value* of a statement, so it only means anything
     * on an expression statement. On `echo`, `if`, `foreach` and friends the
     * generated `(...)` wrapper would be a syntax error; say so instead.
     */
    private function validateMarker(ParsedMarker $marker, Statement $statement): ParsedMarker
    {
        if ($marker->type !== MarkerType::Equals || $statement->kind === StatementKind::Expression) {
            return $marker;
        }

        return ParsedMarker::invalid(
            'the `// =>` marker only works on an expression statement, and this statement is not one',
        );
    }

    private function renderSlot(int $slot, Statement $statement, ParsedMarker $marker): string
    {
        return match ($marker->type) {
            MarkerType::Invalid => $this->renderInvalid($slot, $marker),
            MarkerType::Skip => "\$__docexec_results[{$slot}] = ['status' => 'skip'];\n",
            MarkerType::Equals => $this->renderEquals($slot, $statement, $marker),
            MarkerType::Throws => $this->renderThrows($slot, $statement, $marker),
            MarkerType::Outputs => $this->renderOutputs($slot, $statement, $marker),
            MarkerType::None => $this->renderPlain($slot, $statement),
        };
    }

    private function renderInvalid(int $slot, ParsedMarker $marker): string
    {
        $note = $this->literal((string) $marker->error);

        return "\$__docexec_results[{$slot}] = ['status' => 'fail', 'note' => {$note}];\n";
    }

    private function renderPlain(int $slot, Statement $statement): string
    {
        return <<<PHP
            ob_start();
            try {
                {$statement->code}
                \$__docexec_out = ob_get_clean();
                \$__docexec_results[{$slot}] = ['status' => 'pass', 'output' => \$__docexec_out];
            } catch (\\Throwable \$__docexec_e) {
                \$__docexec_out = ob_get_clean();
                \$__docexec_results[{$slot}] = [
                    'status' => 'fail',
                    'output' => \$__docexec_out,
                    'exception' => \$__docexec_e::class . ': ' . \$__docexec_e->getMessage(),
                    'note' => 'unexpected throw',
                ];
            }

            PHP;
    }

    private function renderEquals(int $slot, Statement $statement, ParsedMarker $marker): string
    {
        $expr = rtrim($statement->code, "; \t\n\r");
        $expectedExpr = (string) $marker->expression;

        return <<<PHP
            ob_start();
            try {
                \$__docexec_actual = ({$expr});
                \$__docexec_out = ob_get_clean();
                \$__docexec_expected = ({$expectedExpr});
                \$__docexec_pass = var_export(\$__docexec_actual, true) === var_export(\$__docexec_expected, true);
                \$__docexec_results[{$slot}] = [
                    'status' => \$__docexec_pass ? 'pass' : 'fail',
                    'output' => \$__docexec_out,
                    'actual' => var_export(\$__docexec_actual, true),
                    'expected' => var_export(\$__docexec_expected, true),
                ];
            } catch (\\Throwable \$__docexec_e) {
                \$__docexec_out = ob_get_clean();
                \$__docexec_results[{$slot}] = [
                    'status' => 'fail',
                    'output' => \$__docexec_out,
                    'exception' => \$__docexec_e::class . ': ' . \$__docexec_e->getMessage(),
                    'note' => 'unexpected throw while evaluating expression',
                ];
            }

            PHP;
    }

    private function renderThrows(int $slot, Statement $statement, ParsedMarker $marker): string
    {
        $class = $this->literal(ltrim((string) $marker->exceptionClass, '\\'));
        $substring = $marker->exceptionSubstring === null
            ? 'true'
            : 'str_contains($__docexec_e->getMessage(), ' . $this->literal($marker->exceptionSubstring) . ')';
        $expectedLabel = $this->literal((string) $marker->exceptionClass);

        return <<<PHP
            ob_start();
            try {
                {$statement->code}
                \$__docexec_out = ob_get_clean();
                \$__docexec_results[{$slot}] = [
                    'status' => 'fail',
                    'output' => \$__docexec_out,
                    'note' => 'expected throw of ' . {$expectedLabel} . ' but none occurred',
                ];
            } catch (\\Throwable \$__docexec_e) {
                \$__docexec_out = ob_get_clean();
                \$__docexec_class = {$class};
                \$__docexec_type_ok = (\$__docexec_e instanceof \$__docexec_class);
                \$__docexec_msg_ok = {$substring};
                \$__docexec_pass = \$__docexec_type_ok && \$__docexec_msg_ok;
                \$__docexec_results[{$slot}] = [
                    'status' => \$__docexec_pass ? 'pass' : 'fail',
                    'output' => \$__docexec_out,
                    'exception' => \$__docexec_e::class . ': ' . \$__docexec_e->getMessage(),
                    'note' => \$__docexec_pass ? null : ('expected ' . {$expectedLabel} . ', got ' . \$__docexec_e::class),
                ];
            }

            PHP;
    }

    private function renderOutputs(int $slot, Statement $statement, ParsedMarker $marker): string
    {
        $expected = $this->literal((string) $marker->expectedOutput);

        return <<<PHP
            ob_start();
            try {
                {$statement->code}
                \$__docexec_out = ob_get_clean();
                \$__docexec_expected = {$expected};
                \$__docexec_pass = trim(\$__docexec_out) === trim(\$__docexec_expected);
                \$__docexec_results[{$slot}] = [
                    'status' => \$__docexec_pass ? 'pass' : 'fail',
                    'output' => \$__docexec_out,
                    'expected' => \$__docexec_expected,
                ];
            } catch (\\Throwable \$__docexec_e) {
                \$__docexec_out = ob_get_clean();
                \$__docexec_results[{$slot}] = [
                    'status' => 'fail',
                    'output' => \$__docexec_out,
                    'exception' => \$__docexec_e::class . ': ' . \$__docexec_e->getMessage(),
                    'note' => 'unexpected throw',
                ];
            }

            PHP;
    }

    private function literal(string $value): string
    {
        return var_export($value, return: true);
    }
}
