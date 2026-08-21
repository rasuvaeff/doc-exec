# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `sample.md` | All four markers (`=>`, `throws`, `outputs`, `skip`), closures and control flow, plus scope-by-heading — checked by doc-exec itself | no |
| `programmatic.php` | Using the `DocExec` API directly and reading per-statement results instead of running the CLI | no |

## Running

From the package root:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install --no-interaction --no-progress --prefer-dist && bin/doc-exec examples/sample.md && php examples/programmatic.php'
```

Or, if PHP is available on the host:

```bash
composer install
bin/doc-exec examples/sample.md
php examples/programmatic.php
```

The example for this package is a Markdown document by design: a doctest
tool's public artifact is the document it checks. `sample.md` is executed on
every build by `composer docs`, so it cannot go stale. `programmatic.php`
covers the other half of the contract — the API you call when you want the
results instead of an exit code.

With no file argument, `bin/doc-exec` checks `README.md` in the current
directory, which is how this package checks its own README.
