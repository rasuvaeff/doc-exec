# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `sample.md` | All four markers (`=>`, `throws`, `outputs`, `skip`) plus scope-by-heading, checked by doc-exec itself | no |

## Running

From the package root:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install --no-interaction --no-progress --prefer-dist && bin/doc-exec examples/sample.md'
```

Or, if PHP is available on the host:

```bash
composer install
bin/doc-exec examples/sample.md
```

Both run the same document `README.md` checks itself with — `bin/doc-exec`
zero-config-scans `README.md` when no file argument is given.
