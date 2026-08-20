<?php

declare(strict_types=1);

namespace Rasuvaeff\DocExec\Report;

use Rasuvaeff\DocExec\BlockResult;
use Rasuvaeff\DocExec\DocumentResult;
use Rasuvaeff\DocExec\StatementOutcome;

/**
 * @api
 */
final readonly class ConsoleReporter
{
    /**
     * @param list<DocumentResult> $results
     */
    public function render(array $results): string
    {
        $lines = [];
        $totalBlocks = 0;
        $failedBlocks = 0;

        foreach ($results as $result) {
            foreach ($result->blocks as $block) {
                ++$totalBlocks;

                if (!$block->passed) {
                    ++$failedBlocks;
                    $lines[] = $this->renderFailure($result, $block);
                }
            }
        }

        $lines[] = $failedBlocks === 0
            ? \sprintf('doc-exec: %d/%d blocks passed', $totalBlocks, $totalBlocks)
            : \sprintf('doc-exec: %d/%d blocks failed', $failedBlocks, $totalBlocks);

        return implode("\n", $lines);
    }

    private function renderFailure(DocumentResult $result, BlockResult $block): string
    {
        $location = \sprintf('%s:%d', $result->file, $block->block->startLine);

        if ($block->processError !== null) {
            return \sprintf("block %s failed to execute\n  %s", $location, $block->processError);
        }

        $details = [];

        foreach ($block->statements as $statementResult) {
            if ($statementResult->outcome !== StatementOutcome::Fail) {
                continue;
            }

            $details[] = \sprintf(
                '  line %d: %s',
                $statementResult->statement->line,
                $statementResult->message ?? 'failed',
            );
        }

        return \sprintf("block %s failed\n%s", $location, implode("\n", $details));
    }
}
