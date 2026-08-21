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
- **Statement splitting goes through `nikic/php-parser`**
  (`Statement\StatementSplitter`), not through a hand-written brace
  heuristic. Statement boundaries are whatever PHP itself considers a
  top-level statement, so closures, `if`/`else`, `try`/`catch`, `match`,
  anonymous classes and `do`/`while` are each one statement. **This replaced a
  splitter that treated any `}` returning bracket depth to 0 as a boundary**;
  that rule looked right because the only cases it was tested against — array
  literal, `foreach`, function declaration — happen to survive it, and every
  brace-bearing expression did not. Do not reintroduce a hand-rolled
  approximation of PHP grammar.
  A marker comment only attaches when it trails the SAME physical line as the
  statement's last token — a comment on its own line is a plain human comment,
  not a marker.
- **A block that does not parse fails alone.** `ScriptBuilder` catches
  `StatementParseError` per block and records a `BlockFailure` instead of
  writing unparsable code into the shared script, where a compile error would
  fail every block in the scope group. Keep that boundary.
- **Marker payloads are validated before they become generated code.** An
  empty `// =>` and a `// =>` on a non-expression statement are
  `MarkerType::Invalid` with a message; they used to be emitted as `()` and
  `(echo "x")`, i.e. syntax errors reported against a temp file line number.
- **`// skip` grammar is strict on purpose** (`skip` or `skip: <reason>`).
  A lenient `skip <anything>` rule fails open: prose starting with "skip"
  silently disables a statement while the block still reports PASS.
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
- **`ProcessRunner` enforces a wall-clock deadline** (30 s default,
  `--timeout=`/`DocExec(timeoutSeconds:)`). A `stream_select` timeout is an
  event, not something to ignore: without it a documentation block containing
  an infinite loop wedges the runner forever, which for a CI gate is worse
  than failing. Result rows arriving on fd 3 are narrowed to strings on
  arrival, so nothing downstream re-checks them.
- **`ProcessRunner` uses non-blocking `stream_select` across three pipes**
  (stdout, stderr, and the fd-3 results channel), not sequential blocking
  reads — a child process writing enough to fill an OS pipe buffer on a
  stream the parent hasn't started draining yet would otherwise deadlock.
  Keep it that way if you touch process I/O.
- **`examples/` is a Markdown document by design.** For a doctest tool the
  public artifact is the document it checks: `examples/sample.md` is executed
  by `composer docs` on every build, so it cannot go stale.
  `examples/programmatic.php` covers the API path (`DocExec` → `BlockResult` →
  `StatementResult`). Note that `bin/package-audit`'s "lint and run
  `examples/*.php`" step therefore only sees the one script.
- **The dogfood gate is only as wide as the corpus.** `composer docs` reported
  12/12 green while closures and `if`/`else` could not run at all, because
  nothing in the corpus used them. When you add a capability, add a block that
  uses it to `examples/sample.md` and to both READMEs — otherwise the gate
  keeps passing for the wrong reason.
- A process-level failure (parse error, fatal not caught by `\Throwable`,
  e.g. OOM) leaves `ProcessOutcome::$results` `null`; `DocExec` reports the
  **entire scope group** as failed with `BlockResult::$processError` set on
  every block in it, since the parse error is a compile-time failure of the
  whole generated script — per-statement granularity is lost in that case,
  and so is which of several blocks in the group actually contains the typo.
  This is a real limitation, not a bug; the property test's self-check
  catalog does not exercise it.
- **`processFailure()` reads both stdout and stderr, stderr first.** PHP
  CLI's parse/fatal-error text lands on stdout under this SAPI's default
  `display_errors`, not stderr, in the `composer:2` image this package is
  built with — checking stderr alone silently drops the one diagnostic a user
  needs to fix a broken example. Covered by
  `DocExecTest::aScopeGroupParseErrorReportsTheDiagnosticNotAGenericMessage`.
- **`composer docs` (`php bin/doc-exec README.md README.ru.md
  examples/sample.md`) is chained into `composer build`.** This package's
  entire thesis is catching doc-rot, so its own docs go stale silently if
  nothing runs `bin/doc-exec` on every build — dogfooding it once by hand is
  not a gate.
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

## Mutation testing

`make mutation` runs Infection with `minMsi: 87`, measured rather than picked:
558 mutants, 433 killed by the suite, 59 killed by timeout, 66 escaped.

Two things about that number are worth knowing before you change it:

- **Testo maps mutants to tests by the `#[Covers]` attribute, not by what the
  test actually executes.** Until `tests/Execution/` existed, every mutant in
  `src/Execution/` was unkillable no matter how thoroughly `DocExecTest`
  exercised that code end to end, and the reported MSI was computed over a
  pool that silently excluded them. If you add a class, add a test class with
  its `#[Covers]`, or its mutants join the same blind spot.
- **The 59 timeout kills are real kills, not noise.** Mutating the drain loop
  or the deadline arithmetic in `ProcessRunner` makes the child never finish,
  which is precisely the failure the deadline exists to catch.

The 66 that escape are dominated by mutations that cannot change behaviour:
`fread()`'s 65536-byte chunk size and the poll interval shifted by one, `+`/`-`
one inside regex quantifiers that still match the same language, and the order
in which the three pipes are drained. Do not silence them with an ignore list —
they are visible on purpose, and an ignore entry would also hide a future
mutation at the same line that does matter.
