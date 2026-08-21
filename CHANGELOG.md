# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-08-21

First release.

- Executes `php doc-exec` fenced code blocks from Markdown against the current
  codebase, in a child `php` process — never `eval()`, never in-process.
- Markers on a statement's trailing comment: `// => <expr>`,
  `// throws <FQCN>[ | <substring>]`, `// outputs <text>`, `// skip[: <reason>]`.
- Blocks under one Markdown heading share a variable scope, in document order.
- Blocks are split into statements by `nikic/php-parser`, so closures,
  `if`/`else`, `try`/`catch`, `match`, anonymous classes and `do`/`while` are
  each one statement.
- A block that is not valid PHP fails on its own, with the line inside the
  block; the other blocks of its scope group still run.
- Each scope group runs under a wall-clock deadline (30 s by default,
  `--timeout=<seconds>`), so a runaway example fails the build instead of
  wedging it.
- CLI: `vendor/bin/doc-exec [--bootstrap=<file>] [--timeout=<seconds>] [--help]
  [file.md ...]`, defaulting to `./README.md`. Exit `0` all passed, `1` a block
  failed, `2` a usage error.
- Runs on Windows as well as POSIX systems: outcomes are reported through a
  temporary file rather than file descriptor 3, which Windows cannot expose
  to a child process. Documents are read the same whether they are saved with
  LF, CRLF or CR line endings.
- Programmatic API: `DocExec::check()` returning `DocumentResult` →
  `BlockResult` → `StatementResult`, plus `StableId` for tracking a block
  across edits elsewhere in the document.
