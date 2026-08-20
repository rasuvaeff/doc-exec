# Sample document

Demonstrates every doc-exec marker plus scope-by-heading. Check it with:

```bash
vendor/bin/doc-exec examples/sample.md
```

## Equals

```php doc-exec
$total = 2 + 3;
$total; // => 5
```

## Throws

```php doc-exec
function riskyDivide(int $a, int $b): int
{
    return intdiv($a, $b);
}

riskyDivide(1, 0); // throws DivisionByZeroError
```

## Outputs

```php doc-exec
echo 'hello';
```

```php doc-exec
echo 'hello'; // outputs hello
```

## Skip

```php doc-exec
throw new \RuntimeException('would fail if actually run'); // skip: illustrative only
```

## Scope resets on a new heading

```php doc-exec
isset($total); // => false
```
