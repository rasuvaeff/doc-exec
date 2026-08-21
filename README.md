# rasuvaeff/doc-exec

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/doc-exec/v)](https://packagist.org/packages/rasuvaeff/doc-exec)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/doc-exec/downloads)](https://packagist.org/packages/rasuvaeff/doc-exec)
[![Build](https://github.com/rasuvaeff/doc-exec/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/doc-exec/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/doc-exec/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/doc-exec/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/doc-exec/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/doc-exec/php)](https://packagist.org/packages/rasuvaeff/doc-exec)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Doctest for PHP: executes `php doc-exec` fenced code blocks from your README
against the current codebase and diffs the result. Catches doc-rot — code
examples that quietly stop matching the code they document — the way
`cargo test --doc` does for Rust.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
- `ext-tokenizer`
- `nikic/php-parser` ^5.7 (a code block is split into statements by a real PHP
  parser, so every construct the language allows stays intact)

## Installation

```bash
composer require --dev rasuvaeff/doc-exec
```

## How it works

A fenced code block only runs when it opts in with the `php doc-exec` info
string — a bare ```` ```php ```` block is illustrative and never executed. Each
top-level PHP **statement** inside a block may carry a trailing marker comment:

````markdown
```php doc-exec
2 + 3; // => 5
intdiv(1, 0); // throws DivisionByZeroError
echo "done"; // outputs done
network_call(); // skip: hits a real API
```
````

| Marker | Checks |
|---|---|
| *(none)* | The statement must not throw. |
| `// => <expr>` | The statement's value matches `<expr>`, compared via `var_export()`. |
| `// throws <FQCN>[ \| <substring>]` | The statement throws an instance of `<FQCN>`, optionally with a message containing `<substring>`. |
| `// outputs <text>` | The statement's stdout, trimmed, equals `<text>`, trimmed. |
| `// skip` or `// skip: <reason>` | The statement is never executed — documentation only. |

The `skip` grammar is deliberately strict: `// skip this in production` is
prose and stays executable. A lenient rule would fail open — the statement
would silently never run while its block still reported PASS.

`// =>` compares with `var_export()`, which compares **state, not identity**:
two distinct objects with equal properties compare equal, and `0.1 + 0.2` is
correctly distinguished from `0.3`. The marker only applies to an expression
statement — on `echo`, `if` or `foreach` it is reported as a mistake rather
than silently doing nothing.

Blocks under the same Markdown heading share one variable scope, in document
order (setup → assert, like a doctest); a new heading starts a fresh scope.
Every document runs in its own child `php` process with the project's
`vendor/autoload.php` on the include path — never `eval()`, never the process
running doc-exec itself.

Because blocks are parsed rather than pattern-matched, anything PHP allows is
one statement — closures, `if`/`else`, `try`/`catch`, `match`, anonymous
classes, `do`/`while`:

```php doc-exec
$double = function (int $n): int {
    return $n * 2;
};

if ($double(4) > 5) {
    $size = 'big';
} else {
    $size = 'small';
}

$size; // => 'big'
```

A block that is not valid PHP is reported against that block, with the line
number inside the block; the other blocks sharing its scope still run.

```php doc-exec
2 + 3; // => 5
```

## Usage

### Command line

```bash
vendor/bin/doc-exec                                           # zero-config: checks ./README.md
vendor/bin/doc-exec docs/*.md                                 # explicit file list
vendor/bin/doc-exec --bootstrap=tests/bootstrap.php README.md # explicit autoloader
vendor/bin/doc-exec --timeout=60 docs/slow.md                 # wall-clock budget per scope group
vendor/bin/doc-exec --help
```

Exit code is `0` when every block passes, `1` otherwise — wire it into CI or
a pre-commit hook. An unknown option is a usage error (exit `2`), never a file
name.

Each scope group runs under a wall-clock deadline — 30 seconds by default,
`--timeout=<seconds>` to change it. A block that loops forever is killed and
reported as a failure, so a documentation mistake cannot wedge a CI job.

### Programmatically

```php doc-exec
use Rasuvaeff\DocExec\MarkdownExtractor;

$markdown = "```php doc-exec\n1 + 1;\n```";
$blocks = (new MarkdownExtractor())->extract($markdown, 'inline.md');
count($blocks); // => 1
```

```php doc-exec
function riskyDivide(int $a, int $b): int
{
    return intdiv($a, $b);
}

riskyDivide(1, 0); // throws DivisionByZeroError
echo "done"; // outputs done
1 + 1; // skip: illustrative only, never executed
```

### Public API

| Class | Description |
|---|---|
| `DocExec` | Facade: `check(string $path): DocumentResult`. Constructor takes `bootstrap:` and `timeoutSeconds:`. |
| `MarkdownExtractor` | Extracts `php doc-exec` (or, with `strict: true`, bare `php`) fenced blocks. |
| `DocumentResult` | `passed(): bool`, `failedIds(): list<string>` (stable ids of failing blocks). |
| `BlockResult` | One fenced block's outcome: its statements, pass/fail, `stableId`. |
| `StatementResult` | One statement's outcome, its `Statement`, its `ParsedMarker`, and the failure message. |
| `StatementOutcome` | `Pass` / `Fail` / `Skip` — the type of `StatementResult::$outcome`. |
| `Statement\Statement` | One statement: `code`, `line` (first line of the statement), `trailingComment`, `kind`. |
| `Statement\StatementKind` | `Expression` / `Import` / `Other` — what PHP considers the statement. |
| `Statement\StatementParseError` | Thrown for a block that is not valid PHP; carries `blockLine`. |
| `Marker\ParsedMarker` | A parsed marker: `type`, plus the payload fields for that type. |
| `Marker\MarkerType` | `None` / `Equals` / `Throws` / `Outputs` / `Skip` / `Invalid`. |
| `Cli\Arguments` | `parse(list<string> $arguments, string $workingDirectory): Arguments`, and `usage()`. |
| `Cli\UsageError` | A command line that could not be understood, as opposed to a failing document. |
| `StableId` | `compute(string $file, int $blockOrdinal, string $code): string` — `sha256`, stable across whitespace-only edits. |
| `AutoloadFinder` | `find(string $startDir): ?string` — walks up for `vendor/autoload.php`, stopping at the project boundary. |
| `Report\ConsoleReporter` | `render(list<DocumentResult> $results): string`. |

## Security

- Blocks execute arbitrary PHP with the permissions of the process running
  `doc-exec` — only run it against documentation you trust, exactly like
  running the project's own test suite.
- Use `// skip: <reason>` for any statement with side effects you do not want
  running in CI (network calls, filesystem writes outside a temp dir).
- The bootstrap path is never derived from untrusted input. The upward search
  stops at the first `composer.json`/`.git` above the document, so it will not
  silently borrow a parent project's autoloader; pass `--bootstrap` explicitly
  when the layout is unusual.
- The child process is bounded by wall-clock time, not by memory or
  filesystem access: a block still runs with the full permissions of the user
  running doc-exec.
- The generated script is written to a temporary file created with
  `tempnam()` (mode 0600, exclusive) and executed with the array form of
  `proc_open` — no shell is involved at any point — and the file is removed
  even if the run throws.

## Examples

See [examples/](examples/) for a runnable, self-checking sample document.

| Script | Shows | Needs server? |
|---|---|---|
| `examples/sample.md` | All four markers, closures and control flow, plus scope-by-heading — checked via `bin/doc-exec` | no |
| `examples/programmatic.php` | Reading per-statement results through the `DocExec` API instead of the CLI | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## License

[BSD-3-Clause](LICENSE.md)
