# rasuvaeff/doc-exec

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/doc-exec/v)](https://packagist.org/packages/rasuvaeff/doc-exec)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/doc-exec/downloads)](https://packagist.org/packages/rasuvaeff/doc-exec)
[![Build](https://github.com/rasuvaeff/doc-exec/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/doc-exec/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/doc-exec/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/doc-exec/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/doc-exec/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/doc-exec/php)](https://packagist.org/packages/rasuvaeff/doc-exec)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Doctest для PHP: исполняет code-блоки с инфострокой `php doc-exec` из вашего
README против текущего кода и сверяет результат. Ловит doc-rot — примеры кода,
которые незаметно перестают соответствовать коду, который документируют, — по
образцу `cargo test --doc` в Rust.

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный API-справочник для модели.

## Требования

- PHP 8.3+
- `ext-tokenizer` (разбивает код-блок на операторы без наивного построчного разбора)

## Установка

```bash
composer require --dev rasuvaeff/doc-exec
```

## Как это работает

Fenced-блок исполняется только при явном opt-in инфостроки `php doc-exec` —
голый ```` ```php ```` блок иллюстративный и никогда не исполняется. Каждый
верхнеуровневый PHP **оператор** внутри блока может нести завершающий маркер:

````markdown
```php doc-exec
2 + 3; // => 5
intdiv(1, 0); // throws DivisionByZeroError
echo "done"; // outputs done
network_call(); // skip: hits a real API
```
````

| Маркер | Что проверяет |
|---|---|
| *(нет)* | Оператор не должен бросить исключение. |
| `// => <expr>` | Значение оператора совпадает с `<expr>` (сравнение через `var_export()`). |
| `// throws <FQCN>[ \| <substring>]` | Оператор бросает экземпляр `<FQCN>`, опционально с подстрокой `<substring>` в сообщении. |
| `// outputs <text>` | stdout оператора (после `trim()`) равен `<text>` (после `trim()`). |
| `// skip[: <причина>]` | Оператор никогда не исполняется — чисто документация. |

Блоки под одним markdown-заголовком делят одну область видимости переменных,
по порядку в документе (setup → assert, как в doctest); новый заголовок
открывает новую область. Каждый документ исполняется в собственном дочернем
процессе `php` с `vendor/autoload.php` проекта на include-пути — никогда не
`eval()`, никогда в процессе самого doc-exec.

```php doc-exec
2 + 3; // => 5
```

## Использование

### Командная строка

```bash
vendor/bin/doc-exec              # zero-config: проверяет ./README.md
vendor/bin/doc-exec docs/*.md    # явный список файлов
vendor/bin/doc-exec --bootstrap=tests/bootstrap.php README.md
```

Код выхода `0`, когда все блоки прошли, иначе `1` — подключайте в CI или
pre-commit хук.

### Программно

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

### Публичный API

| Класс | Описание |
|---|---|
| `DocExec` | Фасад: `check(string $path): DocumentResult`. |
| `MarkdownExtractor` | Извлекает `php doc-exec` (или, при `strict: true`, голые `php`) fenced-блоки. |
| `DocumentResult` | `passed(): bool`, `failedIds(): list<string>` (стабильные id упавших блоков). |
| `BlockResult` | Результат одного fenced-блока: его операторы, pass/fail, `stableId`. |
| `StatementResult` | Результат одного оператора (`Pass`/`Fail`/`Skip`) и сообщение при провале. |
| `StableId` | `compute(string $file, int $blockOrdinal, string $code): string` — `sha256`, стабилен при чисто пробельных правках. |
| `AutoloadFinder` | `find(string $startDir): ?string` — ищет `vendor/autoload.php` вверх по дереву. |
| `Report\ConsoleReporter` | `render(list<DocumentResult> $results): string`. |

## Безопасность

- Блоки исполняют произвольный PHP с правами процесса, запускающего
  `doc-exec`, — запускайте только против документации, которой доверяете,
  точно так же, как запускаете собственный тестовый набор проекта.
- Используйте `// skip: <причина>` для любого оператора с побочными эффектами,
  которые не должны исполняться в CI (сетевые вызовы, запись на диск вне temp).
- Путь bootstrap никогда не выводится из недоверенного ввода; передавайте
  `--bootstrap` явно в любом автоматизированном контексте, где поиск вверх по
  умолчанию мог бы подхватить неожиданный `vendor/autoload.php`.

## Примеры

См. [examples/](examples/) — исполняемый самопроверяющийся пример-документ.

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `examples/sample.md` | Все четыре маркера плюс область видимости по заголовкам, проверяется через `bin/doc-exec` | нет |

## Разработка

PHP/Composer на хосте нет — всё через Docker (`composer:2`):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
