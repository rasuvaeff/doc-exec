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

## Control flow and closures

A block is split into statements by a real PHP parser, so anything the
language allows stays one statement — closures, `if`/`else`, `try`/`catch`,
`match`, anonymous classes, `do`/`while`.

```php doc-exec
$double = function (int $n): int {
    return $n * 2;
};

$double(4); // => 8
```

```php doc-exec
if ($double(4) > 5) {
    $size = 'big';
} else {
    $size = 'small';
}

$size; // => 'big'
```

```php doc-exec
try {
    throw new \RuntimeException('handled here');
} catch (\RuntimeException $e) {
    $message = $e->getMessage();
}

$message; // => 'handled here'
```

## Skip

```php doc-exec
throw new \RuntimeException('would fail if actually run'); // skip: illustrative only
```

## Scope resets on a new heading

```php doc-exec
isset($total); // => false
```
