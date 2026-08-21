<?php

declare(strict_types=1);

use Rasuvaeff\DocExec\DocExec;
use Rasuvaeff\DocExec\StatementOutcome;

require __DIR__ . '/../vendor/autoload.php';

$document = __DIR__ . '/sample.md';
$result = (new DocExec(timeoutSeconds: 15))->check($document);

printf("%s: %s\n", basename($document), $result->passed() ? 'all blocks passed' : 'some blocks failed');

foreach ($result->blocks as $block) {
    printf(
        "\n  block #%d (line %d) — %s\n",
        $block->block->ordinal,
        $block->block->startLine,
        $block->passed ? 'pass' : 'FAIL',
    );

    if ($block->processError !== null) {
        printf("    could not run: %s\n", $block->processError);

        continue;
    }

    foreach ($block->statements as $statement) {
        printf(
            "    %-4s line %d  %s%s\n",
            match ($statement->outcome) {
                StatementOutcome::Pass => 'pass',
                StatementOutcome::Skip => 'skip',
                StatementOutcome::Fail => 'FAIL',
            },
            $statement->statement->line,
            strtok($statement->statement->code, "\n"),
            $statement->message === null ? '' : '  <- ' . $statement->message,
        );
    }
}

// failedIds() returns the stable id of each failing block: the id survives
// edits elsewhere in the document, so it can be tracked over time.
printf("\nfailed block ids: %s\n", $result->failedIds() === [] ? '(none)' : implode(', ', $result->failedIds()));

exit($result->passed() ? 0 : 1);
