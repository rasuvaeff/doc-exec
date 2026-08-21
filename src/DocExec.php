<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec;

use Rasuvaeff\DocExec\Execution\GeneratedScript;
use Rasuvaeff\DocExec\Execution\ProcessOutcome;
use Rasuvaeff\DocExec\Execution\ProcessRunner;
use Rasuvaeff\DocExec\Execution\ScriptBuilder;
use Rasuvaeff\DocExec\Execution\ScriptSlot;
use RuntimeException;

/**
 * Facade: reads a Markdown document, extracts `php doc-exec` blocks, groups
 * them into scopes by heading, and executes each scope group in its own
 * child PHP process.
 *
 * @psalm-import-type ResultRow from \Rasuvaeff\DocExec\Execution\ProcessOutcome
 *
 * @api
 */
final readonly class DocExec
{
    private MarkdownExtractor $extractor;
    private ScriptBuilder $scriptBuilder;
    private ProcessRunner $processRunner;
    private AutoloadFinder $autoloadFinder;

    /**
     * @param positive-int $timeoutSeconds wall-clock budget per scope group
     */
    public function __construct(
        private ?string $bootstrap = null,
        ?MarkdownExtractor $extractor = null,
        ?ScriptBuilder $scriptBuilder = null,
        ?ProcessRunner $processRunner = null,
        ?AutoloadFinder $autoloadFinder = null,
        int $timeoutSeconds = 30,
    ) {
        $this->extractor = $extractor ?? new MarkdownExtractor();
        $this->scriptBuilder = $scriptBuilder ?? new ScriptBuilder();
        $this->processRunner = $processRunner ?? new ProcessRunner(timeoutSeconds: $timeoutSeconds);
        $this->autoloadFinder = $autoloadFinder ?? new AutoloadFinder();
    }

    /**
     * @param non-empty-string $path
     */
    public function check(string $path): DocumentResult
    {
        $markdown = file_get_contents($path);

        if ($markdown === false) {
            throw new RuntimeException(\sprintf('Unable to read "%s"', $path));
        }

        $bootstrap = $this->bootstrap ?? $this->autoloadFinder->find(\dirname($path));

        if ($bootstrap === null) {
            throw new RuntimeException(
                \sprintf('Unable to locate vendor/autoload.php above "%s"; pass an explicit bootstrap', $path),
            );
        }

        $blocks = $this->extractor->extract($markdown, $path);
        $blockResults = [];

        foreach ($this->groupByScope($blocks) as $group) {
            foreach ($this->runGroup($group, $bootstrap) as $blockResult) {
                $blockResults[] = $blockResult;
            }
        }

        return new DocumentResult(file: $path, blocks: $blockResults);
    }

    /**
     * @param list<CodeBlock> $blocks
     * @return list<list<CodeBlock>>
     */
    private function groupByScope(array $blocks): array
    {
        $groups = [];
        $currentKey = null;
        $current = [];

        foreach ($blocks as $block) {
            if ($currentKey !== null && $block->scopeKey !== $currentKey) {
                $groups[] = $current;
                $current = [];
            }

            $currentKey = $block->scopeKey;
            $current[] = $block;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param list<CodeBlock> $group
     * @return list<BlockResult>
     */
    private function runGroup(array $group, string $bootstrap): array
    {
        $script = $this->scriptBuilder->build($group, $bootstrap);

        /** @var array<int, BlockResult> $byOrdinal */
        $byOrdinal = [];

        foreach ($script->blockFailures as $failure) {
            $byOrdinal[$failure->block->ordinal] = $this->blockError($failure->block, $failure->message);
        }

        if ($script->slots !== []) {
            $executable = [];

            foreach ($script->slots as $slot) {
                $executable[$slot->block->ordinal] = $slot->block;
            }

            $outcome = $this->processRunner->run($script->source);

            $results = $outcome->results === null
                ? $this->processFailure(array_values($executable), $outcome)
                : $this->collectBlockResults($script, $outcome);

            foreach ($results as $blockResult) {
                $byOrdinal[$blockResult->block->ordinal] = $blockResult;
            }
        }

        // A block holding nothing runnable (empty, or only comments) still
        // exists in the document: reporting it keeps the "N/M blocks" total
        // honest instead of letting the block silently disappear.
        foreach ($group as $block) {
            $byOrdinal[$block->ordinal] ??= new BlockResult(
                block: $block,
                stableId: StableId::compute($block->file, $block->ordinal, $block->code),
                statements: [],
                passed: true,
            );
        }

        ksort($byOrdinal);

        return array_values($byOrdinal);
    }

    private function blockError(CodeBlock $block, string $error): BlockResult
    {
        return new BlockResult(
            block: $block,
            stableId: StableId::compute($block->file, $block->ordinal, $block->code),
            statements: [],
            passed: false,
            processError: $error,
        );
    }

    /**
     * @param list<CodeBlock> $group
     * @return list<BlockResult>
     */
    private function processFailure(array $group, ProcessOutcome $outcome): array
    {
        // PHP CLI's parse/fatal error text lands on stdout under this SAPI's
        // default display_errors, not stderr — checking stderr alone silently
        // swallows the one diagnostic a user needs to fix a broken example.
        $error = match (true) {
            $outcome->timedOut => 'the block did not finish within the time budget and was killed',
            trim($outcome->stderr) !== '' => trim($outcome->stderr),
            trim($outcome->stdout) !== '' => trim($outcome->stdout),
            default => \sprintf('process exited with code %d without producing a result', $outcome->exitCode),
        };

        $results = [];

        foreach ($group as $block) {
            $results[] = $this->blockError($block, $error);
        }

        return $results;
    }

    /**
     * @return list<BlockResult>
     */
    private function collectBlockResults(GeneratedScript $script, ProcessOutcome $outcome): array
    {
        $results = $outcome->results ?? [];

        /** @var array<int, array{block: CodeBlock, statements: list<StatementResult>}> $byBlock */
        $byBlock = [];

        foreach ($script->slots as $index => $slot) {
            /** @var ResultRow $raw */
            $raw = $results[$index] ?? ['status' => 'fail', 'note' => 'no result reported for this statement'];

            $statementResult = $this->toStatementResult($slot, $raw);
            $ordinal = $slot->block->ordinal;

            $byBlock[$ordinal]['block'] ??= $slot->block;
            $byBlock[$ordinal]['statements'][] = $statementResult;
        }

        $blockResults = [];

        foreach ($byBlock as $entry) {
            $block = $entry['block'];
            $statements = $entry['statements'];
            $passed = true;

            foreach ($statements as $statementResult) {
                if ($statementResult->outcome === StatementOutcome::Fail) {
                    $passed = false;

                    break;
                }
            }

            $blockResults[] = new BlockResult(
                block: $block,
                stableId: StableId::compute($block->file, $block->ordinal, $block->code),
                statements: $statements,
                passed: $passed,
            );
        }

        return $blockResults;
    }

    /**
     * @param ResultRow $raw
     */
    private function toStatementResult(ScriptSlot $slot, array $raw): StatementResult
    {
        $outcome = match ($raw['status'] ?? 'fail') {
            'pass' => StatementOutcome::Pass,
            'skip' => StatementOutcome::Skip,
            default => StatementOutcome::Fail,
        };

        if ($outcome !== StatementOutcome::Fail) {
            return new StatementResult(statement: $slot->statement, marker: $slot->marker, outcome: $outcome, message: null);
        }

        return new StatementResult(
            statement: $slot->statement,
            marker: $slot->marker,
            outcome: $outcome,
            message: $this->buildFailureMessage($raw),
        );
    }

    /**
     * @param ResultRow $raw
     */
    private function buildFailureMessage(array $raw): string
    {
        /** @var list<string> $parts */
        $parts = [];

        if (isset($raw['note'])) {
            $parts[] = $raw['note'];
        }

        if (isset($raw['exception'])) {
            $parts[] = 'exception: ' . $raw['exception'];
        }

        if (isset($raw['expected'], $raw['actual'])) {
            $parts[] = 'expected ' . $raw['expected'] . ', got ' . $raw['actual'];
        } elseif (isset($raw['expected'], $raw['output'])) {
            $parts[] = 'expected output ' . $raw['expected'] . ', got ' . $raw['output'];
        }

        return $parts === [] ? 'failed' : implode('; ', $parts);
    }
}
