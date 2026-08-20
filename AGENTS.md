# AGENTS.md — doc-exec

Guidance for AI agents working on this package. Read before changing code.

## What this is

Doctest for PHP: `Rasuvaeff\DocExec\DocExec::check(string $path)` extracts
`php doc-exec` fenced code blocks from a Markdown document, splits each block
into top-level statements with the tokenizer, and runs them in a dedicated
child `php` process — never `eval()`, never in the process running doc-exec
itself. Trailing `//` marker comments (`=>`, `throws`, `outputs`, `skip`) turn
plain code examples into assertions. CLI entry point is `bin/doc-exec`
(installed as `vendor/bin/doc-exec` by consumers).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never run a doc's code by concatenating blocks and `eval()`-ing them in
   the process running doc-exec.** `Execution\ScriptBuilder` generates one
   self-contained PHP source file per scope group (blocks sharing a Markdown
   heading); `Execution\ProcessRunner` always executes it via `proc_open`
   in a fresh `php` child process, with results returned over a dedicated
   pipe (`php://fd/3`) so they never mix with a block's own stdout. Every
   individual statement is wrapped in its own `try`/`catch` inside the
   generated script — collapsing that into one `try`/`catch` around the
   whole block breaks per-statement markers (a `// throws` earlier in the
   block would swallow every later statement's result) and breaks
   `// outputs`, whose expected text is compared per statement, not per
   block.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **Statement splitting is tokenizer-based, not naive line-splitting**
  (`Statement\StatementSplitter`), so a multi-line array literal, `foreach`,
  or function declaration stays one statement. A marker comment only attaches
  when it trails the SAME physical line as the statement's terminating `;` or
  block-closing `}` — a comment on its own line is a plain human comment, not
  a marker.
- **Markers are opt-in at the fenced-block level** (`php doc-exec` info
  string). A bare ` ```php ` block is illustrative and never runs unless the
  caller passes `MarkdownExtractor(strict: true)` — do not flip this default;
  it is the documented mitigation against false positives on purely
  illustrative snippets (see `DOC-EXEC-PLAN.md` → Риски in the monorepo root).
- **Scope groups reset on every Markdown heading**, any level. Blocks between
  two headings (or before the first one) share one child process and one
  variable scope, executed in document order.
- **`StableId` intentionally changes when a block's code changes** —
  `sha256(file + blockOrdinal + normalize(code))`. Do not make it tolerant of
  code edits; a changed example is a different example, not a continuation.
- **`ProcessRunner` uses non-blocking `stream_select` across three pipes**
  (stdout, stderr, and the fd-3 results channel), not sequential blocking
  reads — a child process writing enough to fill an OS pipe buffer on a
  stream the parent hasn't started draining yet would otherwise deadlock.
  Keep it that way if you touch process I/O.
- A process-level failure (parse error, fatal not caught by `\Throwable`,
  e.g. OOM) leaves `ProcessOutcome::$results` `null`; `DocExec` reports the
  entire scope group as failed with `BlockResult::$processError` set, since
  per-statement granularity is lost in that case — this is a real limitation,
  not a bug, and the property test's self-check catalog does not exercise it.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
