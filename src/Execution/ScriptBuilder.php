<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Execution;

use Rasuvaeff\DocExec\CodeBlock;
use Rasuvaeff\DocExec\Marker\MarkerParser;
use Rasuvaeff\DocExec\Marker\MarkerType;
use Rasuvaeff\DocExec\Marker\ParsedMarker;
use Rasuvaeff\DocExec\Statement\Statement;
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

        foreach ($blocks as $block) {
            foreach ($this->splitter->split($block->code) as $statement) {
                // `use <Ns>\<Class>;` (and `use function …`/`use const …`) is a
                // compile-time import declaration: PHP only allows it at the
                // top level of a file, never inside a block. Wrapping it in
                // try/catch like every other statement would be a syntax
                // error, so it is emitted verbatim and carries no marker.
                if (preg_match('/^use\s/i', $statement->code) === 1) {
                    $body[] = $statement->code . "\n";

                    continue;
                }

                $marker = $this->markerParser->parse($statement->trailingComment);
                $slot = \count($slots);
                $slots[] = new ScriptSlot(block: $block, statement: $statement, marker: $marker);
                $body[] = $this->renderSlot($slot, $statement, $marker);
            }
        }

        $source = "<?php\n\ndeclare(strict_types=1);\n\n"
            . 'require_once ' . $this->literal($bootstrap) . ";\n\n"
            . "\$__docexec_results = [];\n\n"
            . implode("\n", $body)
            . "\n\$__docexec_fp = fopen('php://fd/3', 'w');\n"
            . "fwrite(\$__docexec_fp, json_encode(\$__docexec_results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));\n"
            . "fclose(\$__docexec_fp);\n";

        return new GeneratedScript(source: $source, slots: $slots);
    }

    private function renderSlot(int $slot, Statement $statement, ParsedMarker $marker): string
    {
        return match ($marker->type) {
            MarkerType::Skip => "\$__docexec_results[{$slot}] = ['status' => 'skip'];\n",
            MarkerType::Equals => $this->renderEquals($slot, $statement, $marker),
            MarkerType::Throws => $this->renderThrows($slot, $statement, $marker),
            MarkerType::Outputs => $this->renderOutputs($slot, $statement, $marker),
            MarkerType::None => $this->renderPlain($slot, $statement),
        };
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
        return var_export($value, true);
    }
}
